<?php

namespace App\Http\Controllers;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Support\AccountEmail;
use App\Support\AccountUsername;
use App\Rules\AccountPassword;
use App\Services\LoginEmailChallengeService;
use App\Services\CustomerRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Services\AuthenticationEmailPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AuthController extends Controller
{
    public function cancelRegistration(Request $request, CustomerRegistrationService $registrations)
    {
        return $registrations->cancel($request);
    }

    // ── REGISTER ──────────────────────────────────────────
    public function register(Request $request)
    {
        $browserToken = CustomerRegistrationService::browserToken($request);

        $phone = $request->input('phone');
        $username = $request->input('username');
        $request->merge([
            'email' => AccountEmail::normalize((string) $request->input('email')),
            'username' => is_string($username) ? (trim($username) === '' ? null : trim($username)) : $username,
            'phone' => is_string($phone) && trim($phone) === '' ? null : \App\Support\PhilippineMobile::normalize($phone),
        ]);

        $request->validate(['email' => $request->isMethod('put')
            ? ['required', 'email', 'max:150'] : AccountEmail::rules()]);

        if (! $request->isMethod('put')) {
            $data = $this->registrationData($request, checkAvailability: false);
            $registrations = app(CustomerRegistrationService::class);
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
            try {
                return $registrations->start($request, $data, hash('sha256', $browserToken))
                    ->withCookie(CustomerRegistrationService::browserCookie($request, $browserToken));
            } catch (HttpResponseException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                return response()->json(['success' => false, 'code' => 'email_delivery_failed',
                    'message' => "Couldn't queue the verification email. Please try again."], 503)->header('Cache-Control', 'no-store');
            }
        }
        PendingCustomerRegistration::pruneExpired();
        $credential = $request->validate(['registration_recovery_token' => 'required|string|size:64']);
        $reviewHash = hash('sha256', $credential['registration_recovery_token']);
        $review = User::where('registration_recovery_token_hash', $reviewHash)->first()
            ?? PendingCustomerRegistration::withActiveReview()->where('registration_recovery_token_hash', $reviewHash)->first();
        abort_unless($review && $review->registration_recovery_expires_at && now()->lt($review->registration_recovery_expires_at)
            && (! $review instanceof User || CustomerRegistrationService::recoverableUser($review)), 422, 'This registration review has expired. Verify your email again.');
        abort_unless(strcasecmp($request->input('email'), $review->email) === 0, 422, 'Use the email address you verified.');
        \Illuminate\Support\Facades\Validator::make($request->all(), [
            'password' => AccountPassword::rules('customer', $request->all()),
            'password_confirmation' => ['bail', 'required', 'string', 'same:password'],
        ], AccountPassword::messages())->validate();

        try {
            $result = DB::transaction(function () use ($request) {
                $pendingRegistration = null;
                $credential = $request->validate(['registration_recovery_token' => 'required|string|size:64']);
                $hash = hash('sha256', $credential['registration_recovery_token']);
                $legacy = User::query()->where('registration_recovery_token_hash', $hash)->lockForUpdate()->first();
                $registrant = $legacy ?? PendingCustomerRegistration::query()
                    ->withActiveReview()->where('registration_recovery_token_hash', $hash)->lockForUpdate()->first();
                if (! $registrant || ! $registrant->registration_recovery_expires_at
                    || now()->greaterThanOrEqualTo($registrant->registration_recovery_expires_at)
                    || ($legacy && ! CustomerRegistrationService::recoverableUser($legacy))) {
                    abort(422, 'This registration review has expired. Verify your email again.');
                }
                if (strcasecmp($request->input('email'), $registrant->email) !== 0) {
                    abort(422, 'Use the email address you verified.');
                }
                $pendingRegistration = $legacy
                    ? PendingCustomerRegistration::query()->whereRaw(AccountEmail::lookupColumn().' = ?', [$legacy->email])->lockForUpdate()->first()
                    : $registrant;
                $data = $this->registrationData($request, $pendingRegistration, $legacy);
                unset($data['password']);
                $data['password_hash'] = Hash::make($request->input('password'));
                return app(CustomerRegistrationService::class)->complete($registrant, $data);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['success' => false, 'message' => 'These details are already in use. Continue with email verification or update your details.'], 422);
        }
        return $result;
    }

    private function registrationData(Request $request, ?PendingCustomerRegistration $pendingRegistration = null, ?User $legacy = null, bool $checkAvailability = true): array
    {
        $existing = $legacy ?? $pendingRegistration;
        if ($existing && strcasecmp($existing->email, $request->input('email')) === 0) {
            $request->merge(['email' => $existing->email]);
        }
        $data = $request->validate([
            'first_name' => \App\Support\HumanName::rules(),
            'last_name' => \App\Support\HumanName::rules(),
            'username' => AccountUsername::rules('nullable', $legacy?->username),
            'phone' => [
                'nullable',
                ...\App\Support\PhilippineMobile::rules(required: false),
                ...($checkAvailability ? [Rule::unique('pending_customer_registrations', 'phone')->ignore($pendingRegistration?->getKey())] : []),
            ],
            'email' => [
                ...(($existing && strcasecmp($existing->email, $request->input('email')) === 0)
                    ? ['required', 'email', 'max:150'] : AccountEmail::rules()),
            ],
            'password' => AccountPassword::rules('customer', $request->all(), screen: ! $request->isMethod('put')),
            'password_confirmation' => ['bail', 'required', 'string', 'same:password'],
            'terms_accepted' => ['required', 'accepted'],
        ], AccountPassword::messages() + ['terms_accepted.required' => 'Please agree to the Terms and Conditions.',
            'terms_accepted.accepted' => 'Please agree to the Terms and Conditions.']);
        unset($data['terms_accepted']);
        if ($checkAvailability && ! empty($data['username']) && ! AccountUsername::isAvailable(
            $data['username'], $legacy?->getKey() ?? 0, true, registration: $pendingRegistration,
        )) {
            throw \Illuminate\Validation\ValidationException::withMessages(['username' => 'That username is already in use.']);
        }
        $data['terms_accepted_version'] = CustomerRegistrationService::TERMS_VERSION;
        $data['terms_accepted_at'] = now()->toDateTimeString();
        foreach (['first_name', 'last_name'] as $field) {
            $data[$field] = $existing && $data[$field] === $existing->$field ? $data[$field] : User::normalizeName($data[$field]);
        }
        // Pending completion checks real/reserved accounts in complete(), under
        // the same transaction. Legacy completion must exclude its own account.
        if ($legacy) {
            app(CustomerRegistrationService::class)->assertAccountAvailability($data, true, $legacy);
            AccountEmail::assertAvailable($data['email'], $legacy, false, $pendingRegistration);
        }
        return $data;
    }

    // ── UNIFIED SIGN-IN ───────────────────────────────────
    public function signIn(Request $request, LoginEmailChallengeService $loginChallenges)
    {
        $request->merge([
            'identifier' => trim((string) $request->input('identifier')),
        ]);
        $data = $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
            'remember' => 'sometimes|boolean',
            'login_poll_token' => 'sometimes|nullable|string|size:64',
        ]);

        $identifier = $data['identifier'];
        $normalizedIdentifier = Str::lower($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $field = 'email';
        } elseif (preg_match('/^(0[89]|\+63[89])[0-9]{9}$/D', $identifier)) {
            $field = 'phone';
        } else {
            $field = 'username';
        }

        $lookupIdentifier = match (true) {
            $field === 'email' => $normalizedIdentifier,
            $field === 'phone' && str_starts_with($identifier, '+63') => '0'.substr($identifier, 3),
            default => $identifier,
        };
        // Isolate by identifier AND IP: a remote attacker cannot lock out an account globally.
        $throttleKey = 'sign-in-password:'.hash('sha256', Str::lower($lookupIdentifier).'|'.$request->ip());
        $ipThrottleKey = 'sign-in-ip:'.hash('sha256', (string) $request->ip());
        $lock = Cache::lock($throttleKey.':lock', 30);
        if (! $lock->get()) return $this->signInBusyResponse();

        try {
            $state = Cache::get($throttleKey, ['failures' => 0, 'level' => 0, 'blocked_until' => 0]);
            $seconds = max(0, $state['blocked_until'] - now()->timestamp);
            // Keep this precheck read-only: tooManyAttempts may reset an expired
            // counter while another identifier is starting the next IP window.
            if (RateLimiter::attempts($ipThrottleKey) >= 25) {
                $ipSeconds = RateLimiter::availableIn($ipThrottleKey);
                if ($ipSeconds > 0) {
                    return $this->signInCooldownResponse(max($seconds, $ipSeconds), $ipSeconds >= $seconds);
                }
            }
            if ($seconds > 0) return $this->signInCooldownResponse($seconds);

            $lookupColumn = $field === 'email' ? AccountEmail::lookupColumn() : $field;
            $user = User::whereRaw($lookupColumn.' = ?', [$lookupIdentifier])->first();
            $pendingRegistration = $user
                ? null
                : PendingCustomerRegistration::query()->unexpired()->whereRaw($lookupColumn.' = ?', [$lookupIdentifier])->first();

            if ($user?->requiresPasswordSetup()) {
                return response()->json([
                    'success' => false,
                    'code' => 'password_setup_required',
                    'message' => 'Set up your password using the link sent to your email before signing in.',
                ], 403);
            }

            $passwordHash = $user?->password_hash
                ?? $pendingRegistration?->password_hash
                ?? '$2y$12$phAn9O3fw5n4sHb8YADptOZicYefzNF1mfQlOwFAKzEQ533R6y4iq';
            $passwordMatches = Hash::check($data['password'], $passwordHash);

            if ((! $user && ! $pendingRegistration) || ! $passwordMatches) {
                // Serialize only the short IP update, never password hashing or email delivery.
                $ipLock = Cache::lock($ipThrottleKey.':lock', 5);
                if (! $ipLock->get()) return $this->signInBusyResponse();
                try {
                    if (RateLimiter::tooManyAttempts($ipThrottleKey, 25)) {
                        return $this->signInCooldownResponse(RateLimiter::availableIn($ipThrottleKey), true);
                    }
                    $ipAttempts = RateLimiter::hit($ipThrottleKey, 60);
                    $state['failures']++;
                    if ($state['failures'] >= 5) {
                        $state['level'] = min(3, $state['level'] + 1);
                        $state['failures'] = 0;
                        $state['blocked_until'] = now()->timestamp + [1 => 60, 2 => 300, 3 => 900][$state['level']];
                    }
                    // Sliding inactivity expiry; blocked requests never refresh this record.
                    Cache::put($throttleKey, $state, 900);
                } finally {
                    $ipLock->release();
                }
                if ($user && in_array($user->role, ['admin', 'staff'], true)) {
                    // The attempted account is the subject, never a falsely authenticated actor.
                    app(\App\Services\ActivityRecorder::class)->record('sign_in_failed', 'system', 'account',
                        (string) $user->getKey(), 'Staff sign-in', [], false, null, null, null, 'failed');
                }
                $seconds = max(0, $state['blocked_until'] - now()->timestamp);
                if ($ipAttempts >= 25) {
                    $ipSeconds = RateLimiter::availableIn($ipThrottleKey);
                    return $this->signInCooldownResponse(max($seconds, $ipSeconds), $ipSeconds >= $seconds);
                }
                if ($seconds > 0) return $this->signInCooldownResponse($seconds);

                return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 401);
            }

            // Password proof clears only this pair, even if account or email controls reject next.
            Cache::forget($throttleKey);
        } finally {
            $lock->release();
        }

        if ($pendingRegistration) {
            return response()->json([
                'success' => false,
                'code' => 'email_not_verified',
                'message' => 'Please verify your email address to finish registering your account.',
                'email_not_verified' => true,
                'email' => $pendingRegistration->email,
            ], 403);
        }

        if (! $user->is_active || $user->is_archived || $user->hasEmailRecoveryHold()) {
            return response()->json([
                'success' => false,
                'code' => 'account_disabled',
                'message' => 'Your account has been disabled. Please contact the clinic.',
            ], 403);
        }

        // Block unverified customers (admins/staff skip this check)
        if (! in_array($user->role, ['admin', 'staff']) && ! $user->email_verified_at) {
            return response()->json([
                'success' => false,
                'code' => 'email_not_verified',
                'message' => 'Please verify your email address before signing in. Check your inbox for the verification link.',
                'email_not_verified' => true,
                'email' => $user->email,
            ], 403);
        }

        if (in_array($user->role, ['customer', 'admin', 'staff'], true)) {
            try {
                $challenge = $loginChallenges->send(
                    $user,
                    (bool) ($data['remember'] ?? false),
                    $data['login_poll_token'] ?? null,
                );
            } catch (HttpResponseException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'success' => false,
                    'message' => 'Your credentials were confirmed, but the login confirmation email could not be queued. Please try again.',
                ], 503);
            }

            return response()->json([
                'success' => true,
                'code' => 'login_confirmation_required',
                'message' => $challenge['email_delivery_queued'] ? 'Your sign-in email is queued. Enter the code when it arrives.'
                    : 'Enter your sign-in code to continue.',
                'requires_login_confirmation' => true,
                'email_delivery_queued' => $challenge['email_delivery_queued'],
                'email_delivery_token' => $challenge['delivery_token'],
                ...app(AuthenticationEmailPolicy::class)->status($user->email, 'login'),
                'login_poll_token' => $challenge['poll_token'],
                'expires_in' => $challenge['expires_in'],
                'requires_new_code' => $challenge['requires_new_code'],
                'email' => $user->email,
            ], 202);
        }

        $token = app(\App\Services\AccountSessionService::class)->issue($user);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'phone_change_pending' => $user->role === 'customer' && $user->phone_verified_at !== null
                    && \Illuminate\Support\Facades\Schema::hasTable('phone_verification_attempts')
                    && DB::table('phone_verification_attempts')->where('user_id', $user->getKey())
                        ->where('original_phone', $user->phone)->where('original_verified_at', $user->getRawOriginal('phone_verified_at'))
                        ->whereNotIn('status', ['consumed', 'superseded'])->exists(),
                'role' => $user->role,
                'staff_type' => $user->staff_type,
                'staff_subrole' => $user->staff_subrole,
                'clinic_permissions' => $user->clinicPermissions(),
                'staff_permissions' => $user->staffPermissions(),
            ],
        ], 200);
    }

    private function signInCooldownResponse(int $seconds, bool $ipLimited = false)
    {
        $seconds = max(1, $seconds);
        return response()->json([
            'success' => false,
            'code' => $ipLimited ? 'sign_in_ip_cooldown' : 'sign_in_password_cooldown',
            'message' => 'Too many attempts. Please wait before signing in again.',
            'retry_after' => $seconds,
        ], 429)->header('Retry-After', (string) $seconds)->header('Cache-Control', 'no-store');
    }

    private function signInBusyResponse()
    {
        return response()->json([
            'success' => false, 'code' => 'sign_in_busy',
            'message' => 'A sign-in attempt is in progress. Please try again.',
        ], 429)->header('Retry-After', '1')->header('Cache-Control', 'no-store');
    }

    // ── LOGOUT ────────────────────────────────────────────
    public function logout(Request $request)
    {
        return app(\App\Services\RememberedDeviceService::class)->logout($request);
    }

    // ── GET CURRENT USER ──────────────────────────────────
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'sms_verification' => \App\Services\CustomerPhoneVerificationService::flags(),
            'sidebar_revision' => in_array($user->role, ['admin', 'staff'], true) ? $user->sidebarRevision() : null,
            'user' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'phone_verification_status' => trim((string) $user->phone) === ''
                    ? 'not_added'
                    : ($user->phone_verified_at !== null ? 'verified' : 'unverified'),
                'role' => $user->role,
                'staff_type' => $user->staff_type,
                'staff_subrole' => $user->staff_subrole,
                'clinic_permissions' => $user->clinicPermissions(),
                'staff_permissions' => $user->staffPermissions(),
            ],
        ], 200);
    }
}
