<?php

namespace App\Services;

use App\Support\AccountEmail;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

class CustomerRegistrationService
{
    public const BROWSER_COOKIE = 'bethlehem_registration_browser';

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
        if ($requestedAt <= now()->subHour()->timestamp) return;
        try {
            DB::transaction(function () use ($email, $details, $browserHash, $requestedAt) {
                if ($details !== null) {
                    PendingCustomerRegistration::pruneExpired();
                    $identifiers = function ($query) use ($email, $details) {
                        $query->whereRaw(AccountEmail::lookupColumn().' = ?', [$email]);
                        foreach (['phone', 'username'] as $field) {
                            if (! empty($details[$field])) $query->orWhere($field, $details[$field]);
                        }
                    };
                    if (User::query()->where($identifiers)->lockForUpdate()->first()
                        || PendingCustomerRegistration::query()->where($identifiers)->lockForUpdate()->first()) return;
                    $registrant = PendingCustomerRegistration::create([
                        ...$details, 'email' => $email,
                        'registration_browser_token_hash' => $browserHash,
                        'registration_edit_token_hash' => hash('sha256', Str::random(64)),
                    ]);
                } else {
                    $user = User::query()->whereRaw(AccountEmail::lookupColumn().' = ?', [$email])->lockForUpdate()->first();
                    $registrant = $user ? (self::recoverableUser($user) ? $user : null)
                        : PendingCustomerRegistration::query()->unexpired()->whereRaw(AccountEmail::lookupColumn().' = ?', [$email])->lockForUpdate()->first();
                    // Retried or out-of-order work must not replace a newer link.
                    if (! $registrant || $registrant->created_at?->timestamp > $requestedAt
                        || $registrant->email_verification_last_sent_at?->timestamp >= $requestedAt) return;
                }
                if ($registrant instanceof PendingCustomerRegistration
                    && ($registrant->hasActiveReview()
                        || $registrant->retentionExpiresAt()->lessThan(now()->addSeconds(RegistrationMagicLinkService::MIN_DELIVERY_SECONDS)))) return;
                app(RegistrationMagicLinkService::class)->issue($registrant, allowImmediate: false);
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // A concurrent registration won; never overwrite it or send another link.
        }
    }

    public static function canEdit(PendingCustomerRegistration $registration, Request $request): bool
    {
        $token = $request->input('registration_token');
        return ! $registration->retentionExpired() && self::trustsBrowser($registration, $request)
            && is_string($token) && strlen($token) === 64 && $registration->registration_edit_token_hash
            && hash_equals($registration->registration_edit_token_hash, hash('sha256', $token));
    }

    public static function usableLink(PendingCustomerRegistration $registration): bool
    {
        return ! $registration->retentionExpired() && $registration->email_verification_token
            && $registration->email_verification_last_sent_at
            && RegistrationMagicLinkService::expiresAt($registration)?->isFuture();
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
            'details' => $registrant->only(['first_name', 'last_name', 'username', 'email', 'phone']),
            'message' => 'Email confirmed. Review your details and choose a password to complete registration.',
        ])->header('Cache-Control', 'no-store');
    }

    // Caller holds the registrant row lock and transaction, including session creation.
    public function complete(User|PendingCustomerRegistration $registrant, ?array $details = null)
    {
        if ($registrant instanceof PendingCustomerRegistration) {
            if ($registrant->retentionExpired() && (! $details || ! $registrant->hasActiveReview())) $this->unavailable();
            $values = $details ?? $registrant->only(['first_name', 'last_name', 'username', 'email', 'phone', 'password_hash']);
            $conflict = User::query()->where(function ($query) use ($values) {
                $query->whereRaw(AccountEmail::lookupColumn().' = ?', [$values['email']]);
                if ($values['phone'] ?? null) $query->orWhere('phone', $values['phone']);
                if ($values['username'] ?? null) $query->orWhere('username', $values['username']);
            })->lockForUpdate()->exists();
            if ($conflict) $this->unavailable();
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
