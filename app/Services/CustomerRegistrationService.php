<?php

namespace App\Services;

use App\Support\AccountEmail;
use App\Models\PendingCustomerRegistration;
use App\Models\PendingStaffAccount;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Cookie;

class CustomerRegistrationService
{
    public const BROWSER_COOKIE = 'bethlehem_registration_browser';
    public const TERMS_VERSION = '2026-11-01';

    public static function hasCurrentTerms(User|PendingCustomerRegistration $registrant): bool
    {
        return $registrant->terms_accepted_version === self::TERMS_VERSION && $registrant->terms_accepted_at !== null;
    }

    public function assertAccountAvailability(array $details, bool $lock = false, ?User $except = null): void
    {
        $query = User::query()->where(function ($query) use ($details) {
            $query->whereRaw(AccountEmail::lookupColumn().' = ?', [$details['email']]);
            if (! empty($details['phone'])) $query->orWhere('phone', $details['phone']);
            if (! empty($details['username'])) $query->orWhereRaw(
                (DB::getDriverName() === 'sqlite' ? 'username COLLATE NOCASE' : 'username').' = ?', [$details['username']]);
        })->when($except, fn ($query) => $query->whereKeyNot($except->getKey()));
        if ($lock) $query->lockForUpdate();
        $accounts = $query->get(['email', 'phone', 'username']);
        // Older staff invitation records still reserve their live credentials.
        if (Schema::hasTable('pending_staff_accounts')) {
            $reserved = PendingStaffAccount::query()->where('expires_at', '>', now())->where(function ($query) use ($details) {
                $query->whereRaw(AccountEmail::lookupColumn().' = ?', [$details['email']]);
                if (! empty($details['username'])) $query->orWhereRaw(
                    (DB::getDriverName() === 'sqlite' ? 'username COLLATE NOCASE' : 'username').' = ?', [$details['username']]);
            });
            if ($lock) $reserved->lockForUpdate();
            $accounts = $accounts->concat($reserved->get(['email', 'username']));
        }
        $errors = $conflicts = [];
        foreach ($accounts as $account) {
            if (strcasecmp($account->email ?? '', $details['email']) === 0) {
                $errors['email'] = ['An account with this email already exists.'];
                $conflicts['email'] = 'account_exists';
            }
            if (! empty($details['phone']) && $account->phone === $details['phone']) {
                $errors['phone'] = ['An account with this mobile number already exists.'];
                $conflicts['phone'] = 'account_exists';
            }
            if (! empty($details['username']) && strcasecmp($account->username ?? '', $details['username']) === 0) {
                $errors['username'] = ['That username is already in use.'];
                $conflicts['username'] = 'username_taken';
            }
        }
        if ($errors) throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'message' => reset($errors)[0], 'errors' => $errors, 'conflicts' => $conflicts,
        ], 422)->header('Cache-Control', 'no-store'));
    }

    public function start(Request $request, array $details, string $browserHash): \Illuminate\Http\JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $details, $browserHash) {
                PendingCustomerRegistration::pruneExpired();
                $this->assertAccountAvailability($details, true);
                $collisions = PendingCustomerRegistration::query()->where(function ($query) use ($details) {
                    $query->whereRaw(AccountEmail::lookupColumn().' = ?', [$details['email']]);
                    foreach (['phone', 'username'] as $field) {
                        if (! empty($details[$field])) $query->orWhere($field, $details[$field]);
                    }
                })->lockForUpdate()->get();
                foreach ($collisions as $pending) {
                    if (strcasecmp($pending->email, $details['email']) !== 0 || ! self::canContinue($pending, $request)) {
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                            'success' => false, 'code' => 'registration_pending',
                            'message' => 'These details are reserved by an unfinished registration. Use its verification email or try again after it expires.',
                            'retry_after' => max(1, ($pending->registration_recovery_expires_at ?? $pending->retentionExpiresAt())->timestamp - now()->timestamp),
                        ], 422)->header('Cache-Control', 'no-store'));
                    }
                    $pending->delete();
                }
                $credential = Str::random(64);
                $pending = PendingCustomerRegistration::create([
                    ...$details, 'registration_browser_token_hash' => $browserHash,
                    'registration_edit_token_hash' => hash('sha256', $credential),
                ]);
                $receipt = app(AuthenticationEmailPolicy::class)->send($pending->email,
                    fn () => app(RegistrationMagicLinkService::class)->issue($pending, allowImmediate: false));
                return response()->json([
                    'success' => true, 'requires_verification' => true, 'registration_state' => 'queued',
                    'message' => 'Verification email queued. Delivery is not confirmed.',
                    'email' => $pending->email, 'registration_token' => $credential,
                    'registration_expires_at' => $pending->retentionExpiresAt()->toIso8601String(),
                    'email_delivery_token' => $receipt, 'email_delivery_queued' => true,
                    ...app(AuthenticationEmailPolicy::class)->status($pending->email),
                ], 202)->header('Cache-Control', 'no-store');
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->assertAccountAvailability($details);
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'success' => false, 'code' => 'registration_pending',
                'message' => 'These details are reserved by an unfinished registration. Use its verification email or try again after it expires.',
            ], 422)->header('Cache-Control', 'no-store'));
        }
    }

    public function cancel(Request $request): \Illuminate\Http\JsonResponse
    {
        // UseAccountSessionCookie enforces the shared XHR/origin protections.
        $data = $request->validate(['registration_token' => 'required|string|size:64']);
        DB::transaction(function () use ($data, $request) {
            $hash = hash('sha256', $data['registration_token']);
            $pending = PendingCustomerRegistration::query()
                ->where(fn ($query) => $query->where('registration_edit_token_hash', $hash)
                    ->orWhere('registration_recovery_token_hash', $hash)
                    ->orWhere('email_verification_token', $hash))->lockForUpdate()->first();
            if ($pending && ((self::trustsBrowser($pending, $request) && $pending->registration_edit_token_hash === $hash)
                || ($pending->hasActiveReview() && $pending->registration_recovery_token_hash === $hash)
                || ($pending->email_verification_token === $hash && ! $pending->retentionExpired()
                    && RegistrationMagicLinkService::expiresAt($pending)?->isFuture()))) $pending->delete();
        }, 3);
        return response()->json(['success' => true])->header('Cache-Control', 'no-store');
    }

    public function requestPublic(string $email, ?array $details, string $browserHash): \Illuminate\Http\JsonResponse
    {
        $connection = config('queue.connections.'.config('queue.default').'.driver');
        if (! app()->environment('testing') && $connection !== 'database') {
            throw new \RuntimeException('Registration requires the durable database queue.');
        }
        try {
            app(AuthenticationEmailPolicy::class)->send($email, fn () => app(\Illuminate\Contracts\Bus\Dispatcher::class)
                ->dispatch(new \App\Jobs\ProcessPublicRegistration($email, $details, $browserHash, now()->timestamp)));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException) {
            // Destination history must not become a public account-status oracle.
            // No new work is queued and no existing link is invalidated.
        }
        return response()->json([
            'success' => true, 'requires_verification' => true, 'registration_state' => 'requested',
            'message' => 'Request received. Email delivery is not confirmed.',
            'resend_after' => (int) config('authentication.email.cooldown', 60),
        ], 202)->header('Cache-Control', 'no-store');
    }

    public function processPublicRequest(string $email, ?array $details, string $browserHash, int $requestedAt): void
    {
        // Replayed or delayed public work must never recreate an expired signup.
        // Pre-deployment creation jobs are obsolete: only live rows issue mail.
        if ($details !== null || $requestedAt <= now()->subHour()->timestamp) return;
        try {
            DB::transaction(function () use ($email, $requestedAt) {
                $user = User::query()->whereRaw(AccountEmail::lookupColumn().' = ?', [$email])->lockForUpdate()->first();
                $registrant = $user ? (self::recoverableUser($user) ? $user : null)
                    : PendingCustomerRegistration::query()->unexpired()->whereRaw(AccountEmail::lookupColumn().' = ?', [$email])->lockForUpdate()->first();
                // Retried or out-of-order work must not replace a newer link.
                if (! $registrant || $registrant->created_at?->timestamp > $requestedAt
                    || $registrant->email_verification_last_sent_at?->timestamp >= $requestedAt) return;
                if ($registrant instanceof PendingCustomerRegistration
                    && ($registrant->hasActiveReview()
                        || $registrant->retentionExpiresAt()->lessThan(now()->addSeconds(RegistrationMagicLinkService::MIN_DELIVERY_SECONDS)))) return;
                app(RegistrationMagicLinkService::class)->issue($registrant, allowImmediate: false);
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // A concurrent registration won; never overwrite it or send another link.
        }
    }

    public static function canContinue(PendingCustomerRegistration $registration, Request $request): bool
    {
        $token = $request->input('registration_token');
        return ! $registration->retentionExpired() && self::trustsBrowser($registration, $request)
            && is_string($token) && strlen($token) === 64 && $registration->registration_edit_token_hash
            && hash_equals($registration->registration_edit_token_hash, hash('sha256', $token));
    }

    public static function browserToken(Request $request): string
    {
        $token = $request->cookie(self::BROWSER_COOKIE);
        return is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token) ? $token : Str::random(64);
    }

    public static function browserCookie(Request $request, string $token): Cookie
    {
        return Cookie::create(self::BROWSER_COOKIE, $token)->withPath('/api')->withHttpOnly(true)->withSameSite('lax')
            ->withSecure((bool) config('session.secure') || $request->isSecure() || str_starts_with(config('app.url'), 'https://'))
            ->withExpires(now()->addHour());
    }

    public static function trustsBrowser(PendingCustomerRegistration $registration, Request $request): bool
    {
        $token = $request->cookie(self::BROWSER_COOKIE);
        return is_string($token) && $registration->registration_browser_token_hash
            && hash_equals($registration->registration_browser_token_hash, hash('sha256', $token));
    }

    public static function recoverableUser(User $user): bool
    {
        return $user->role === 'customer' && ! $user->email_verified_at
            && $user->is_active && ! $user->is_archived && ! $user->account_deleted_at;
    }

    // Called only after email ownership is proved, under the registrant row lock.
    public function authorizeReview(User|PendingCustomerRegistration $registrant)
    {
        $grant = Str::random(64);
        $values = [
            ...self::clearedVerification(),
            'registration_recovery_token_hash' => hash('sha256', $grant),
            'registration_recovery_expires_at' => now()->addMinutes(10),
        ];
        if ($registrant instanceof PendingCustomerRegistration) {
            $values['registration_edit_token_hash'] = null;
            $values['registration_browser_token_hash'] = null;
        }
        $registrant->update($values);

        return response()->json([
            'success' => true,
            'requires_registration_review' => true,
            'registration_recovery_token' => $grant,
            'registration_recovery_expires_at' => $values['registration_recovery_expires_at']->toIso8601String(),
            'details' => $registrant->only(['first_name', 'last_name', 'username', 'email', 'phone']),
            'message' => 'Email confirmed. Review your details and choose a password to complete registration.',
        ])->header('Cache-Control', 'no-store');
    }

    // Caller holds the registrant row lock and transaction, including session creation.
    public function complete(User|PendingCustomerRegistration $registrant, ?array $details = null)
    {
        $acceptance = $details ?? $registrant->only(['terms_accepted_version', 'terms_accepted_at']);
        if (($acceptance['terms_accepted_version'] ?? null) !== self::TERMS_VERSION || empty($acceptance['terms_accepted_at'])) {
            throw ValidationException::withMessages(['terms_accepted' => 'Please agree to the Terms and Conditions.']);
        }
        if ($registrant instanceof PendingCustomerRegistration) {
            if ($registrant->retentionExpired() && (! $details || ! $registrant->hasActiveReview())) $this->unavailable();
            $values = $details ?? $registrant->only(['first_name', 'last_name', 'username', 'email', 'phone', 'password_hash',
                'terms_accepted_version', 'terms_accepted_at']);
            $this->assertAccountAvailability($values, true);
            $user = User::create([
                ...$values, 'role' => 'customer', 'customer_tier' => 'new',
                'is_active' => true, 'is_archived' => false, 'email_verified_at' => now(),
            ]);
            $registrant->delete();
        } else {
            if (! self::recoverableUser($registrant)) $this->unavailable();
            $user = $registrant;
            if ($details && ($details['phone'] ?? null) !== $user->phone) {
                $details['phone_verified_at'] = null;
            }
            $user->update([
                ...($details ?? []), ...self::clearedVerification(),
                'email_verified_at' => now(), 'registration_recovery_token_hash' => null,
                'registration_recovery_expires_at' => null,
            ]);
            // Preserve the existing user ID, pets, bookings, tier, and history.
            $user->tokens()->delete();
            PendingCustomerRegistration::query()->whereRaw(AccountEmail::lookupColumn().' = ?', [$user->email])->delete();
        }

        $token = app(AccountSessionService::class)->issue($user);
        Cache::put('registration-handoff:'.explode('|', $token)[0], false, 600);
        return response()->json([
            'success' => true,
            'message' => 'Email verified and account registered successfully.',
            'token' => $token,
            'user' => $user->only(['user_id', 'first_name', 'last_name', 'email', 'phone', 'role']),
        ])->header('Cache-Control', 'no-store');
    }

    public static function clearedVerification(): array
    {
        return [
            'email_verification_token' => null, 'email_verification_expires_at' => null,
            'email_verification_code_hash' => null, 'email_verification_last_sent_at' => null,
            'email_verification_attempts' => 0,
        ];
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages([
            'registration' => 'These registration details are no longer available. Please verify your email again or update your details.',
        ]);
    }
}
