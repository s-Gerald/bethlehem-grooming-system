<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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
        return ['login-code-account:'.$user->getKey() => (int) config('authentication.otp_account_attempts', 20),
            'login-code-ip:'.hash('sha256', (string) request()->ip()) => (int) config('authentication.otp_ip_attempts', 25)];
    }

    public static function sourceHash(): string
    {
        return hash_hmac('sha256', (string) request()->ip(), (string) config('app.key'));
    }

    public static function assertAttemptBudget(User $user): void
    {
        if ($response = self::attemptBudgetResponse($user)) throw new HttpResponseException($response);
    }

    public static function attemptBudgetResponse(User $user)
    {
        $seconds = RateLimiter::availableIn(self::sourceKey($user).':cooldown');
        foreach (self::attemptKeys($user) as $key => $limit) {
            // Read-only outside the confirmation lock: do not let issuance
            // clear an expired IP counter while another account starts its window.
            if (RateLimiter::attempts($key) >= max(1, $limit)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }
        return $seconds ? response()->json(['success' => false, 'code' => 'login_code_locked',
            'message' => 'Too many verification attempts. Try again later.', 'retry_after' => $seconds], 429)
            ->header('Retry-After', (string) $seconds)->header('Cache-Control', 'no-store') : null;
    }

    private static function sourceKey(User $user): string
    {
        return 'login-code-source:'.$user->getKey().':'.self::sourceHash();
    }

    // Called only for a genuine mismatch, under the account row and IP locks.
    public static function recordIncorrectAttempt(User $user): void
    {
        foreach (self::attemptKeys($user) as $key => $limit) {
            RateLimiter::hit($key, (int) config('authentication.otp_attempt_window', 900));
        }
        $key = self::sourceKey($user);
        $state = Cache::get($key.':history', ['failures' => 0, 'level' => 0]);
        $state['failures']++;
        $cooldowns = config('authentication.otp_source_cooldowns', [60, 180, 300]);
        if ($state['failures'] >= max(1, (int) config('authentication.otp_source_attempts', 5))) {
            $state['failures'] = 0;
            $state['level'] = min($state['level'] + 1, count($cooldowns));
            RateLimiter::hit($key.':cooldown', max(1, (int) $cooldowns[$state['level'] - 1]));
        }
        Cache::put($key.':history', $state, max(1, (int) config('authentication.otp_source_reset_seconds', 1800)));
    }

    public static function clearSourceHistory(User $user): void
    {
        Cache::forget(self::sourceKey($user).':history');
        RateLimiter::clear(self::sourceKey($user).':cooldown');
    }

    public static function expiredResponse()
    {
        return response()->json(['success' => false, 'code' => 'login_transaction_expired', 'expired' => true,
            'message' => 'Sign-in expired. Please sign in again.'], 422)->header('Cache-Control', 'no-store');
    }

    public static function challengeState(LoginEmailChallenge $challenge): array
    {
        return ['expires_in' => max(0, $challenge->expires_at->timestamp - now()->timestamp),
            'requires_new_code' => $challenge->failed_attempts >= self::maxAttempts()];
    }

    public static function resendAfter(?LoginEmailChallenge $challenge): int
    {
        return $challenge ? max(0, ($challenge->last_sent_at ?? $challenge->updated_at)->timestamp + (int) config('authentication.email.cooldown', 60) - now()->timestamp) : 0;
    }

    public function resend(string $plainPollToken): array
    {
        $pollTokenHash = hash('sha256', $plainPollToken);

        $pending = LoginEmailChallenge::where('poll_token_hash', $pollTokenHash)->first();
        if (! $pending || $pending->approved_at || now()->gte($pending->expires_at)) {
            throw new HttpResponseException(self::expiredResponse());
        }
        $recipient = User::find($pending->user_id);
        if (! $recipient) throw new HttpResponseException(self::expiredResponse());
        self::assertAttemptBudget($recipient);
        $issuance = app(AuthenticationEmailPolicy::class)->send($recipient->email, fn () => DB::transaction(function () use ($plainPollToken, $pollTokenHash): array {
            $userId = LoginEmailChallenge::where('poll_token_hash', $pollTokenHash)->value('user_id');
            $user = $userId ? User::whereKey($userId)->lockForUpdate()->first() : null;
            $challenge = LoginEmailChallenge::query()
                ->where('poll_token_hash', $pollTokenHash)
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->approved_at || now()->greaterThanOrEqualTo($challenge->expires_at)) {
                throw new HttpResponseException(self::expiredResponse());
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
                    'code' => 'email_cooldown',
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
                'failed_attempts' => 0,
                'last_sent_at' => now(),
            ]);
            $receipt = AuthenticationMailDelivery::queue($user, new ConfirmLoginNotification($code), $challenge);
            $challenge->update(['delivery_receipt' => $receipt]);
            return ['email_delivery_token' => $receipt, ...self::challengeState($challenge)];
        }), 'login');
        return [...$issuance, ...app(AuthenticationEmailPolicy::class)->status($recipient->email, 'login')];
    }

    public function send(User $user, bool $rememberMe, ?string $pendingToken = null): array
    {
        if ($pendingToken) {
            $resumed = DB::transaction(function () use ($user, $pendingToken): ?array {
                $lockedUser = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                self::assertAttemptBudget($lockedUser);
                $challenge = LoginEmailChallenge::where('user_id', $lockedUser->getKey())
                    ->where('poll_token_hash', hash('sha256', $pendingToken))->lockForUpdate()->first();
                if (! $challenge || $challenge->approved_at || now()->gte($challenge->expires_at)) return null;
                return ['poll_token' => $pendingToken, 'delivery_token' => $challenge->delivery_receipt,
                    'email_delivery_queued' => false, ...self::challengeState($challenge),
                    ...app(AuthenticationEmailPolicy::class)->status($user->email, 'login')];
            });
            if ($resumed) {
                app(AccountSecurityService::class)->passwordConfirmed($user);
                return $resumed;
            }
        }
        EmailVerificationController::assertMailCanBeDelivered();

        $plainPollToken = Str::random(64);
        $pollTokenHash = hash('sha256', $plainPollToken);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tokenHash = hash_hmac('sha256', $code, $plainPollToken);
        $expiresAt = now()->addMinutes(max(1, (int) config('authentication.otp_minutes', 10)));
        self::assertAttemptBudget($user);
        $receipt = app(AuthenticationEmailPolicy::class)->send($user->email, fn () => DB::transaction(function () use (
            $user,
            $rememberMe,
            $tokenHash,
            $pollTokenHash,
            $code,
            $expiresAt,
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

            self::assertAttemptBudget($lockedUser);
            $active = LoginEmailChallenge::where('user_id', $lockedUser->getKey())->where('expires_at', '>', now())->whereNull('approved_at');
            if ((clone $active)->count() >= max(1, (int) config('authentication.outstanding_challenges', 10))) {
                $seconds = max(1, \Illuminate\Support\Carbon::parse($active->min('expires_at'))->timestamp - now()->timestamp);
                throw new HttpResponseException(response()->json(['success' => false, 'code' => 'login_challenges_limited',
                    'message' => 'Too many sign-in requests. Try again later.', 'retry_after' => $seconds], 429)
                    ->header('Retry-After', (string) $seconds));
            }
            $challenge = new LoginEmailChallenge(['user_id' => $lockedUser->user_id]);
            $challenge->fill([
                'token_hash' => $tokenHash,
                'poll_token_hash' => $pollTokenHash,
                'remember_me' => $rememberMe,
                'expires_at' => $expiresAt,
                'approved_at' => null,
                'last_sent_at' => now(),
                'source_hash' => self::sourceHash(),
                'failed_attempts' => 0,
            ])->save();

            $receipt = AuthenticationMailDelivery::queue($lockedUser, new ConfirmLoginNotification($code), $challenge);
            $challenge->update(['delivery_receipt' => $receipt]);
            return $receipt;
        }), 'login');

        app(AccountSecurityService::class)->passwordConfirmed($user);

        return [
            'poll_token' => $plainPollToken,
            'delivery_token' => $receipt,
            'resend_after' => (int) config('authentication.email.cooldown', 60),
            'email_delivery_queued' => true,
            'expires_in' => max(0, $expiresAt->timestamp - now()->timestamp),
            'requires_new_code' => false,
        ];
    }
}
