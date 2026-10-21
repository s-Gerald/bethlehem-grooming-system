<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginEmailChallengeService
{
    public const COOLDOWN_SECONDS = 60;
    public const MAX_ATTEMPTS = 5;

    public static function resendAfter(?LoginEmailChallenge $challenge): int
    {
        return $challenge ? max(0, $challenge->updated_at->timestamp + self::COOLDOWN_SECONDS - now()->timestamp) : 0;
    }

    public function resend(string $plainPollToken): string
    {
        $pollTokenHash = hash('sha256', $plainPollToken);

        return DB::transaction(function () use ($plainPollToken, $pollTokenHash): string {
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

            $user = User::find($challenge->user_id);

            if (! $user || ! $user->is_active || $user->is_archived) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code' => 'account_disabled',
                    'message' => 'This account cannot complete sign-in. Please contact the clinic.',
                ], 403));
            }

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
                'expires_at' => now()->addMinutes(max(
                    1,
                    (int) config('app.login_confirmation_ttl_minutes', 15),
                )),
                'approved_at' => null,
            ]);
            return AuthenticationMailDelivery::queue($user, new ConfirmLoginNotification($code), $challenge);
        });
    }

    public function send(User $user, bool $rememberMe): array
    {
        EmailVerificationController::assertMailCanBeDelivered();

        $plainPollToken = Str::random(64);
        $pollTokenHash = hash('sha256', $plainPollToken);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tokenHash = hash_hmac('sha256', $code, $plainPollToken);
        $receipt = DB::transaction(function () use (
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
                || $lockedUser->is_archived) {
                throw new \RuntimeException('This account is not eligible for login confirmation.');
            }

            $challenge = LoginEmailChallenge::query()
                ->where('user_id', $lockedUser->user_id)
                ->lockForUpdate()
                ->first();
            $challenge ??= new LoginEmailChallenge(['user_id' => $lockedUser->user_id]);
            $challenge->fill([
                'token_hash' => $tokenHash,
                'poll_token_hash' => $pollTokenHash,
                'remember_me' => $rememberMe,
                'expires_at' => now()->addMinutes(max(
                    1,
                    (int) config('app.login_confirmation_ttl_minutes', 15),
                )),
                'approved_at' => null,
            ])->save();

            return AuthenticationMailDelivery::queue($user, new ConfirmLoginNotification($code), $challenge);
        });

        return [
            'poll_token' => $plainPollToken,
            'delivery_token' => $receipt,
            'resend_after' => self::COOLDOWN_SECONDS,
        ];
    }
}
