<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginEmailChallengeService
{
    public static function maxAttempts(): int
    {
        return max(1, (int) config('authentication.otp_attempts', 5));
    }

    public static function attemptKeys(User $user): array
    {
        return ['login-code-account:'.$user->getKey() => (int) config('authentication.otp_account_attempts', 5),
            'login-code-ip:'.hash('sha256', (string) request()->ip()) => (int) config('authentication.otp_ip_attempts', 25)];
    }

    public static function assertAttemptBudget(User $user): void
    {
        if ($response = self::attemptBudgetResponse($user)) throw new HttpResponseException($response);
    }

    public static function attemptBudgetResponse(User $user)
    {
        foreach (self::attemptKeys($user) as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, max(1, $limit))) {
                $seconds = max(1, RateLimiter::availableIn($key));
                return response()->json(['success' => false, 'code' => 'login_code_locked',
                    'message' => 'Too many attempts. Sign in again.', 'retry_after' => $seconds], 429)
                    ->header('Retry-After', (string) $seconds)->header('Cache-Control', 'no-store');
            }
        }
        return null;
    }

    public static function resendAfter(?LoginEmailChallenge $challenge): int
    {
        return $challenge ? max(0, $challenge->updated_at->timestamp + (int) config('authentication.email.cooldown', 60) - now()->timestamp) : 0;
    }

    public function resend(string $plainPollToken): array
    {
        $pollTokenHash = hash('sha256', $plainPollToken);

        $userId = LoginEmailChallenge::where('poll_token_hash', $pollTokenHash)->value('user_id');
        $recipient = $userId ? User::find($userId) : null;
        if (! $recipient) throw new HttpResponseException(response()->json(['success' => false, 'expired' => true,
            'message' => 'This sign-in session has expired. Please sign in again.'], 422));
        self::assertAttemptBudget($recipient);
        $receipt = app(AuthenticationEmailPolicy::class)->send($recipient->email, fn () => DB::transaction(function () use ($plainPollToken, $pollTokenHash): string {
            $userId = LoginEmailChallenge::where('poll_token_hash', $pollTokenHash)->value('user_id');
            $user = $userId ? User::whereKey($userId)->lockForUpdate()->first() : null;
            $challenge = LoginEmailChallenge::query()
                ->where('poll_token_hash', $pollTokenHash)
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->approved_at || now()->greaterThanOrEqualTo($challenge->expires_at)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'This sign-in session has expired. Please sign in again.',
                    'expired' => true,
                ], 422));
            }

            if (! $user || ! $user->is_active || $user->is_archived || $user->hasEmailRecoveryHold()) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'account_disabled',
                    'message' => 'This account cannot complete sign-in. Please contact the clinic.',
                ], 403));
            }

            self::assertAttemptBudget($user);
            EmailVerificationController::assertMailCanBeDelivered();

            if ($remaining = self::resendAfter($challenge)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Please wait before requesting another code.',
                    'retry_after' => $remaining,
                ], 429)->header('Retry-After', (string) $remaining));
            }

            do {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while (hash_equals($challenge->token_hash, hash_hmac('sha256', $code, $plainPollToken)));
            $newTokenHash = hash_hmac('sha256', $code, $plainPollToken);

            $challenge->update([
                'token_hash' => $newTokenHash,
                'approved_at' => null,
            ]);
            return AuthenticationMailDelivery::queue($user, new ConfirmLoginNotification($code), $challenge);
        }));
        return ['email_delivery_token' => $receipt, ...app(AuthenticationEmailPolicy::class)->status($recipient->email)];
    }

    public function send(User $user, bool $rememberMe): array
    {
        EmailVerificationController::assertMailCanBeDelivered();

        $plainPollToken = Str::random(64);
        $pollTokenHash = hash('sha256', $plainPollToken);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tokenHash = hash_hmac('sha256', $code, $plainPollToken);
        self::assertAttemptBudget($user);
        $receipt = app(AuthenticationEmailPolicy::class)->send($user->email, fn () => DB::transaction(function () use (
            $user,
            $rememberMe,
            $tokenHash,
            $pollTokenHash,
            $code,
        ): string {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $eligibleRole = in_array($lockedUser->role, ['customer', 'admin', 'staff'], true);
            $customerEmailIsVerified = $lockedUser->role !== 'customer'
                || $lockedUser->email_verified_at;

            if (! $eligibleRole
                || ! $customerEmailIsVerified
                || ! $lockedUser->is_active
                || $lockedUser->is_archived || $lockedUser->hasEmailRecoveryHold()) {
                throw new \RuntimeException('This account is not eligible for login confirmation.');
            }

            $challenge = LoginEmailChallenge::query()
                ->where('user_id', $lockedUser->user_id)
                ->lockForUpdate()
                ->first();
            self::assertAttemptBudget($lockedUser);
            $challenge ??= new LoginEmailChallenge(['user_id' => $lockedUser->user_id]);
            $challenge->fill([
                'token_hash' => $tokenHash,
                'poll_token_hash' => $pollTokenHash,
                'remember_me' => $rememberMe,
                'expires_at' => now()->addMinutes(max(
                    1,
                    (int) config('authentication.otp_minutes', 10),
                )),
                'approved_at' => null,
            ])->save();

            return AuthenticationMailDelivery::queue($lockedUser, new ConfirmLoginNotification($code), $challenge);
        }));

        return [
            'poll_token' => $plainPollToken,
            'delivery_token' => $receipt,
            'resend_after' => (int) config('authentication.email.cooldown', 60),
        ];
    }
}
