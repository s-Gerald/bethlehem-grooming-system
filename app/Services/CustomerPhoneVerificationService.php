<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPhoneVerificationService
{
    public function __construct(private IprogSmsOtpService $provider) {}

    public static function flags(): array
    {
        return ['available' => (bool) config('services.sms_verification.available', false),
            'enforced' => (bool) config('services.sms_verification.enforced', false)];
    }

    public static function assertPreRegistration(?User $user): void
    {
        if (config('services.sms_verification.enforced', false)
            && (! $user || self::required($user))) {
            self::fail('Verify your phone number before pre-registering.', 403, 'phone_verification_required');
        }
    }

    public static function required(User $user): bool
    {
        return $user->role === 'customer'
            && (! preg_match('/^09[0-9]{9}$/D', (string) $user->phone) || ! $user->phone_verified_at);
    }

    public function send(User $user, string $phone, string $ip, ?string $generation = null): array
    {
        $this->available();

        return $this->locked($user->getKey(), $phone, function () use ($user, $phone, $ip, $generation) {
            $user = User::findOrFail($user->getKey());
            $this->usable($user);
            $attempt = $this->attempt($user->getKey());
            if ($generation !== null && (! $attempt || $attempt->generation !== $generation || $attempt->phone !== $phone
                || in_array($attempt->status, ['consumed', 'superseded'], true))) {
                self::fail('This verification has changed. Start again.', 409);
            }
            $this->budget($attempt);
            if ($attempt && Carbon::parse($attempt->resend_at)->isFuture()) {
                self::fail('Please wait before sending another code.', 429, 'resend_cooldown', [
                    'resend_after' => max(1, (int) ceil(now()->diffInSeconds(Carbon::parse($attempt->resend_at), false))),
                ]);
            }
            $this->uniquePhone($user->getKey(), $phone);
            if (DB::table('phone_verification_attempts')->where('phone', $phone)->where('user_id', '!=', $user->getKey())
                ->where('expires_at', '>', now())->whereNotIn('status', ['consumed', 'superseded'])->exists()) {
                self::fail('This number has an active verification. Try again later.', 409);
            }
            $this->reserveSend($user->getKey(), $phone, $ip);
            $id = (string) Str::uuid();
            $reset = ! $attempt || Carbon::parse($attempt->attempts_reset_at)->lte(now());
            $values = [
                'phone' => $phone, 'generation' => $id, 'status' => 'sending', 'code_fingerprint' => null,
                'original_phone' => $user->phone, 'original_verified_at' => $user->getRawOriginal('phone_verified_at'),
                'expires_at' => now()->addMinutes(5), 'resend_at' => now()->addSeconds(config('services.sms_verification.resend_seconds', 60)),
                'failed_attempts' => $reset ? 0 : $attempt->failed_attempts,
                'attempts_reset_at' => $reset ? now()->addSeconds(config('services.sms_verification.attempt_window_seconds', 3600)) : $attempt->attempts_reset_at,
                'updated_at' => now(),
            ];
            DB::table('phone_verification_attempts')->updateOrInsert(['user_id' => $user->getKey()], [...$values, 'created_at' => now()]);
            $expires = $this->provider->send($phone, $id);
            if (! $expires) {
                // An ambiguous send may have been accepted. Reserve its expiry and cooldown.
                DB::table('phone_verification_attempts')->where('generation', $id)->where('status', 'sending')->update(['status' => 'uncertain']);
                self::fail('SMS could not be confirmed. Wait a minute, then try again.', 503);
            }
            $updated = DB::table('phone_verification_attempts')->where('generation', $id)->where('status', 'sending')
                ->update(['status' => 'active', 'expires_at' => $expires, 'code_fingerprint' => $this->provider->sentFingerprint()]);
            if (! $updated) {
                self::fail('This verification has changed. Start again.', 409);
            }

            return $this->timing($this->attempt($user->getKey()));
        });
    }

    public function resend(User $user, string $generation, string $ip): array
    {
        $this->available();
        $attempt = $this->attempt($user->getKey());
        if (! $attempt || $attempt->generation !== $generation) {
            self::fail('Start phone verification again.', 409);
        }

        return $this->send($user, $attempt->phone, $ip, $generation);
    }

    public function verify(User $user, string $generation, #[\SensitiveParameter] string $code): array
    {
        $this->available();
        $attempt = $this->attempt($user->getKey());
        if (! $attempt || $attempt->generation !== $generation) {
            self::fail('Start phone verification again.', 409);
        }

        return $this->locked($user->getKey(), $attempt->phone, function () use ($user, $generation, $code) {
            $attempt = $this->attempt($user->getKey());
            if (! $attempt || $attempt->generation !== $generation || ! in_array($attempt->status, ['active', 'confirmed'], true)) {
                self::fail('This verification is no longer active. Start again.', 409);
            }
            if (Carbon::parse($attempt->expires_at)->lte(now())) {
                self::fail('Code expired. Send a new code.', 422);
            }
            $matches = hash_equals((string) $attempt->code_fingerprint, $this->provider->fingerprint($generation, $code));
            if ($attempt->status !== 'confirmed' || ! $matches) {
                $this->budget($attempt);
            }
            if (! $matches) {
                DB::table('phone_verification_attempts')->where('generation', $generation)->increment('failed_attempts');
                self::fail('Incorrect code. Please try again.', 422);
            }
            $this->usable(User::findOrFail($user->getKey()));
            if ($attempt->status !== 'confirmed') {
                DB::table('phone_verification_attempts')->where('generation', $generation)->where('status', 'active')
                    ->update(['status' => 'verifying', 'failed_attempts' => $attempt->failed_attempts + 1]);
                if (! $this->provider->verify($attempt->phone, $code)) {
                    DB::table('phone_verification_attempts')->where('generation', $generation)->where('status', 'verifying')->update(['status' => 'active']);
                    self::fail('Code could not be verified. Check it or send a new code.', 503);
                }
                // Persist provider proof separately so a local commit failure can be retried
                // with this same user, generation and fingerprint without replaying the provider OTP.
                $confirmed = DB::table('phone_verification_attempts')->where('generation', $generation)->where('status', 'verifying')->update(['status' => 'confirmed']);
                if (! $confirmed) {
                    self::fail('This verification has changed. Start again.', 409);
                }
            }
            try {
                DB::transaction(function () use ($user, $generation) {
                    $current = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                    $attempt = DB::table('phone_verification_attempts')->where('user_id', $user->getKey())->lockForUpdate()->first();
                    if (! $attempt || $attempt->generation !== $generation || $attempt->status !== 'confirmed'
                        || Carbon::parse($attempt->expires_at)->lte(now())
                        || $current->phone !== $attempt->original_phone
                        || $current->getRawOriginal('phone_verified_at') !== $attempt->original_verified_at) {
                        self::fail('Your account changed. Start phone verification again.', 409);
                    }
                    $this->usable($current);
                    $this->uniquePhone($current->getKey(), $attempt->phone);
                    DB::table('users')->where('user_id', $current->getKey())->update(['phone' => $attempt->phone, 'phone_verified_at' => now()]);
                    DB::table('phone_verification_attempts')->where('generation', $generation)->update(['status' => 'consumed', 'code_fingerprint' => null]);
                });
            } catch (HttpResponseException|ValidationException $e) {
                throw $e;
            } catch (\Throwable) {
                self::fail('Verification could not be saved. Retry the same code before it expires, or start again.', 503);
            }

            return ['success' => true, 'message' => 'Phone number verified.'];
        });
    }

    private function available(): void
    {
        if (! config('services.sms_verification.available', false) || ! $this->provider->configured()) {
            self::fail('SMS verification is unavailable. Please try again later or contact the clinic.', 503);
        }
        if (! app()->environment('testing') && (Cache::getStore() instanceof ArrayStore
            || Cache::getStore() instanceof NullStore)) {
            self::fail('SMS verification is unavailable. Please contact the clinic.', 503);
        }
    }

    private function usable(User $user): void
    {
        if ($user->role !== 'customer' || ! $user->is_active || $user->is_archived || ! $user->email_verified_at || $user->account_deleted_at) {
            self::fail('This account cannot verify a phone number.', 403);
        }
    }

    private function attempt(int $userId): ?object
    {
        return DB::table('phone_verification_attempts')->where('user_id', $userId)->first();
    }

    private function uniquePhone(int $userId, string $phone): void
    {
        if (User::where('phone', $phone)->where('user_id', '!=', $userId)->exists()
            || (Schema::hasTable('pending_customer_registrations') && DB::table('pending_customer_registrations')->where('phone', $phone)->exists())) {
            throw ValidationException::withMessages(['phone' => 'This phone number is already associated with another account.']);
        }
    }

    private function budget(?object $attempt): void
    {
        if ($attempt && Carbon::parse($attempt->attempts_reset_at)->isFuture()
            && $attempt->failed_attempts >= config('services.sms_verification.max_attempts', 5)) {
            self::fail('Too many attempts. Try again in an hour.', 429, 'attempts_exceeded');
        }
    }

    private function locked(int $userId, string $phone, callable $callback): array
    {
        $userLock = Cache::lock('sms:user:'.$userId, 30);
        $phoneLock = Cache::lock('sms:phone:'.hash('sha256', $phone), 30);
        if (! $userLock->get()) {
            self::fail('Verification is already in progress. Please wait.', 409);
        }
        try {
            if (! $phoneLock->get()) {
                self::fail('Verification is already in progress. Please wait.', 409);
            }
            try {
                return $callback();
            } finally {
                $phoneLock->release();
            }
        } finally {
            $userLock->release();
        }
    }

    private function reserveSend(int $userId, string $phone, string $ip): void
    {
        $lock = Cache::lock('sms:send-budget', 5);
        if (! $lock->get()) {
            self::fail('Please try sending again in a moment.', 429);
        }
        try {
            $limits = [
                ['user:'.$userId, 'user_hourly_limit', 5, 3600],
                ['phone:'.hash('sha256', $phone), 'phone_hourly_limit', 5, 3600],
                ['ip:'.hash('sha256', $ip), 'ip_hourly_limit', 20, 3600],
                ['global:hour', 'global_hourly_limit', 100, 3600],
                ['global:day', 'global_daily_limit', 500, 86400],
            ];
            foreach ($limits as [$key, $setting, $default, $seconds]) {
                if (RateLimiter::tooManyAttempts('sms:'.$key, config('services.sms_verification.'.$setting, $default))) {
                    self::fail('SMS sending limit reached. Please try again later.', 429, 'sms_send_limit', ['resend_after' => RateLimiter::availableIn('sms:'.$key)]);
                }
            }
            foreach ($limits as [$key, $setting, $default, $seconds]) {
                RateLimiter::hit('sms:'.$key, $seconds);
            }
        } finally {
            $lock->release();
        }
    }

    private function timing(object $attempt): array
    {
        return ['success' => true, 'message' => 'Verification code sent.', 'challenge_id' => $attempt->generation,
            'expires_in' => max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($attempt->expires_at), false))),
            'resend_after' => max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($attempt->resend_at), false)))];
    }

    private static function fail(string $message, int $status, string $code = 'phone_verification_failed', array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['success' => false, 'code' => $code, 'message' => $message, ...$extra], $status));
    }
}
