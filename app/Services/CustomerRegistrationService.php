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
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

class CustomerRegistrationService
{
    public const BROWSER_COOKIE = 'bethlehem_registration_browser';
    public const TERMS_VERSION = '2026-11-01';

    public function start(Request $request, string $email, string $browserHash): \Illuminate\Http\JsonResponse
    {
        $credential = $request->validate(['registration_token' => 'required|string|size:64'])['registration_token'];
        $hash = hash('sha256', $credential);
        $lock = Cache::lock('registration-start:'.$hash, 15);
        if (! $lock->get()) return $this->json(['success' => false, 'message' => 'Registration is starting. Try again.'], 409);
        try {
            return DB::transaction(function () use ($request, $email, $browserHash, $credential, $hash) {
                $pending = PendingCustomerRegistration::where('registration_edit_token_hash', $hash)->lockForUpdate()->first();
                if ($pending) {
                    $this->assertBound($pending, $request);
                    if ($pending->email !== $email) throw ValidationException::withMessages(['email' => 'Start a new verification for the changed email.']);
                    return $this->json([...$this->attemptState($pending), 'requires_verification' => true,
                        'registration_state' => 'resumed', 'registration_token' => $credential,
                        ...app(AuthenticationEmailPolicy::class)->status($email)], 202);
                }
                $this->assertAccountAvailability(['email' => $email], true);
                $pending = PendingCustomerRegistration::create([
                    'email' => $email, 'registration_browser_token_hash' => $browserHash,
                    'registration_edit_token_hash' => $hash,
                ]);
                $receipt = app(RegistrationMagicLinkService::class)->send($pending);
                return $this->json([...$this->attemptState($pending->fresh()), 'requires_verification' => true,
                    'registration_state' => 'queued', 'registration_token' => $credential,
                    'email_delivery_token' => $receipt, 'email_delivery_queued' => true,
                    ...app(AuthenticationEmailPolicy::class)->status($email)], 202);
            }, 3);
        } finally { $lock->release(); }
    }

    public function status(Request $request)
    {
        return $this->bound($request, fn ($pending) => $this->json($this->attemptState($pending)));
    }

    public function cancel(Request $request)
    {
        return $this->bound($request, function ($pending) {
            if (! $pending->registration_completed_at) $pending->delete();
            return $this->json(['success' => true]);
        });
    }

    public function resend(Request $request)
    {
        return $this->bound($request, function ($pending) {
            if ($pending->registration_approved_at || $pending->registration_completed_at) return $this->json($this->attemptState($pending));
            $receipt = app(RegistrationMagicLinkService::class)->send($pending);
            return $this->json([...$this->attemptState($pending->fresh()), 'email_delivery_token' => $receipt,
                'email_delivery_queued' => true, ...app(AuthenticationEmailPolicy::class)->status($pending->email)]);
        });
    }

    public function finalize(Request $request)
    {
        // Screen passwords outside the transaction, recheck authority under lock.
        $credential = $request->validate(['registration_token' => 'required|string|size:64'])['registration_token'];
        $pending = PendingCustomerRegistration::where('registration_edit_token_hash', hash('sha256', $credential))->first();
        $this->assertBound($pending, $request);
        if (! $pending->registration_approved_at) $this->unavailable('registration_not_approved', 'Verify your email before completing registration.');
        $details = null;
        if (! $pending->registration_completed_at) {
            $details = $this->registrationData($request);
            if ($details['email'] !== $pending->email) throw ValidationException::withMessages(['email' => 'Use the exact email address you verified.']);
            $details['password_hash'] = Hash::make($details['password']);
            unset($details['password'], $details['password_confirmation']);
        }
        try {
            return $this->bound($request, function ($pending) use ($request, $details) {
                if ($pending->registration_completed_at) return $this->sessionResponse($pending, $request);
                if (! $pending->registration_approved_at || ! $details) $this->unavailable();
                $this->assertAccountAvailability($details, true);
                $user = User::create([...$details, 'role' => 'customer', 'customer_tier' => 'new',
                    'is_active' => true, 'is_archived' => false, 'email_verified_at' => now()]);
                $issued = app(AccountSessionService::class)->issue($user);
                $session = PersonalAccessToken::findToken($issued);
                // Recover the SAME first session after response loss, without
                // persisting its plaintext or ciphertext in the attempt row.
                $session->update(['token' => hash('sha256', $this->sessionSecret($pending, $request))]);
                $pending->update(['registration_completed_at' => now(), 'completed_session_id' => $session->getKey()]);
                return $this->sessionResponse($pending, $request);
            });
        } catch (UniqueConstraintViolationException) {
            $this->assertAccountAvailability($details ?? ['email' => $pending->email]);
            return $this->json(['success' => false, 'code' => 'registration_conflict',
                'message' => 'These details are already in use. Update your details and try again.'], 422);
        }
    }

    private function registrationData(Request $request): array
    {
        $phone = $request->input('phone');
        $username = $request->input('username');
        $request->merge([
            'email' => AccountEmail::normalize((string) $request->input('email')),
            'username' => is_string($username) ? (trim($username) === '' ? null : trim($username)) : $username,
            'phone' => is_string($phone) && trim($phone) === '' ? null : \App\Support\PhilippineMobile::normalize($phone),
        ]);
        $data = $request->validate([
            'first_name' => \App\Support\HumanName::rules(),
            'last_name' => \App\Support\HumanName::rules(),
            'username' => \App\Support\AccountUsername::rules('nullable'),
            'phone' => ['nullable', ...\App\Support\PhilippineMobile::rules(required: false)],
            'email' => AccountEmail::rules(),
            'password' => \App\Rules\AccountPassword::rules('customer', $request->all()),
            'password_confirmation' => ['bail', 'required', 'string', 'same:password'],
            'terms_accepted' => ['required', 'accepted'],
        ], \App\Rules\AccountPassword::messages() + [
            'terms_accepted.required' => 'Please agree to the Terms and Conditions.',
            'terms_accepted.accepted' => 'Please agree to the Terms and Conditions.',
        ]);
        unset($data['terms_accepted']);
        $data['terms_accepted_version'] = self::TERMS_VERSION;
        $data['terms_accepted_at'] = now();
        foreach (['first_name', 'last_name'] as $field) $data[$field] = User::normalizeName($data[$field]);
        return $data;
    }

    private function sessionSecret(PendingCustomerRegistration $pending, Request $request): string
    {
        return hash_hmac('sha256', 'customer-registration-session|'.$pending->getKey().'|'.$request->input('registration_token')
            .'|'.$request->cookie(self::BROWSER_COOKIE), (string) config('app.key'));
    }

    private function sessionResponse(PendingCustomerRegistration $pending, Request $request)
    {
        $secret = $this->sessionSecret($pending, $request);
        $session = PersonalAccessToken::findToken($pending->completed_session_id.'|'.$secret);
        $user = $session?->tokenable;
        if (! $user instanceof User || $user->role !== 'customer' || $user->email !== $pending->email
            || ! app(AccountSessionService::class)->isUsable($session, $user)) $this->unavailable();
        return $this->json(['success' => true, 'token' => $session->getKey().'|'.$secret,
            'user' => $user->only(['user_id', 'first_name', 'last_name', 'email', 'phone', 'role'])]);
    }

    private function bound(Request $request, callable $action)
    {
        $data = $request->validate(['registration_token' => 'required|string|size:64']);
        return DB::transaction(function () use ($request, $data, $action) {
            $pending = PendingCustomerRegistration::where('registration_edit_token_hash', hash('sha256', $data['registration_token']))->lockForUpdate()->first();
            $this->assertBound($pending, $request);
            return $action($pending);
        }, 3);
    }

    private function assertBound(?PendingCustomerRegistration $pending, Request $request): void
    {
        if (! $pending || ! self::trustsBrowser($pending, $request)) $this->unavailable();
        if ($pending->retentionExpired()) $this->unavailable('registration_expired', 'Registration expired. Start again.');
    }

    private function attemptState(PendingCustomerRegistration $pending): array
    {
        return ['success' => true, 'email' => $pending->email,
            'state' => $pending->registration_completed_at ? 'completed' : ($pending->registration_approved_at ? 'approved' : 'pending'),
            'registration_expires_at' => $pending->retentionExpiresAt()->toIso8601String(),
            'verification_link_expires_at' => RegistrationMagicLinkService::expiresAt($pending)?->toIso8601String()];
    }

    public static function browserToken(Request $request): string
    {
        $token = $request->cookie(self::BROWSER_COOKIE);
        return is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token) ? $token : Str::random(64);
    }

    public static function browserCookie(Request $request, string $token): Cookie
    {
        return \App\Http\Middleware\UseAccountSessionCookie::cookie($request, self::BROWSER_COOKIE, $token, now()->addHour());
    }

    public static function trustsBrowser(PendingCustomerRegistration $registration, Request $request): bool
    {
        $token = $request->cookie(self::BROWSER_COOKIE);
        return is_string($token) && $registration->registration_browser_token_hash
            && hash_equals($registration->registration_browser_token_hash, hash('sha256', $token));
    }

    private function unavailable(string $code = 'registration_unavailable', string $message = 'This registration is unavailable. Start again.'): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException($this->json(['success' => false, 'code' => $code, 'message' => $message], 422));
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }

    private function assertAccountAvailability(array $details, bool $lock = false): void
    {
        $query = User::query()->where(function ($query) use ($details) {
            $query->whereRaw(AccountEmail::lookupColumn().' = ?', [$details['email']]);
            if (! empty($details['phone'])) $query->orWhere('phone', $details['phone']);
            if (! empty($details['username'])) $query->orWhereRaw(
                (DB::getDriverName() === 'sqlite' ? 'username COLLATE NOCASE' : 'username').' = ?', [$details['username']]);
        });
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

}
