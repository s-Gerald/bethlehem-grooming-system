<?php

namespace App\Http\Controllers;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Support\AccountEmail;
use App\Services\LoginEmailChallengeService;
use App\Services\CustomerRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Services\AuthenticationEmailPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AuthController extends Controller
{
    // ── REGISTER ──────────────────────────────────────────
    public function register(Request $request)
    {
        $browserToken = CustomerRegistrationService::browserToken($request);

        $phone = $request->input('phone');
        $request->merge([
            'email' => AccountEmail::normalize((string) $request->input('email')),
            'username' => filled($request->input('username'))
                ? trim((string) $request->input('username'))
                : null,
            'phone' => is_string($phone) && trim($phone) === '' ? null : $phone,
        ]);

        $request->validate(['email' => $request->isMethod('put')
            ? ['required', 'email', 'max:150'] : AccountEmail::rules()]);

        if (! $request->isMethod('put')) {
            // Specific status requires a credential issued before this request,
            // bound to this browser. An email address alone proves no ownership.
            if (is_string($request->input('registration_token')) && strlen($request->input('registration_token')) === 64) {
                $pending = PendingCustomerRegistration::query()->unexpired()
                    ->where('registration_edit_token_hash', hash('sha256', $request->input('registration_token')))->first();
                if ($pending && strcasecmp($pending->email, $request->input('email')) === 0
                    && CustomerRegistrationService::canEdit($pending, $request)) {
                    $linkAvailable = CustomerRegistrationService::usableLink($pending);
                    return response()->json([
                        'success' => true, 'requires_verification' => true, 'registration_state' => 'resumed',
                        'email_delivery_queued' => false, 'verification_link_available' => $linkAvailable,
                        'verification_link_expires_at' => $linkAvailable
                            ? $pending->email_verification_expires_at->min($pending->email_verification_last_sent_at->copy()
                                ->addMinutes(max(1, (int) config('authentication.magic_link_minutes', 30))))->toIso8601String() : null,
                        'email' => $pending->email, 'registration_token' => $request->input('registration_token'),
                        ...app(AuthenticationEmailPolicy::class)->status($pending->email),
                    ], 202)->header('Cache-Control', 'no-store');
                }
            }
            $data = $this->registrationData($request, checkAvailability: false);
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
            return app(CustomerRegistrationService::class)->requestPublic($data['email'], $data, hash('sha256', $browserToken))
                ->withCookie(CustomerRegistrationService::browserCookie($request, $browserToken));
        }
        PendingCustomerRegistration::pruneExpired();

        try {
            $result = DB::transaction(function () use ($request, $browserToken) {
                $pendingRegistration = null;
                if ($request->isMethod('put') && $request->has('registration_recovery_token')) {
                    $credential = $request->validate(['registration_recovery_token' => 'required|string|size:64']);
                    $hash = hash('sha256', $credential['registration_recovery_token']);
                    $legacy = User::query()->where('registration_recovery_token_hash', $hash)->lockForUpdate()->first();
                    $registrant = $legacy ?? PendingCustomerRegistration::query()
                        ->unexpired()->where('registration_recovery_token_hash', $hash)->lockForUpdate()->first();
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
                }
                if ($request->isMethod('put')) {
                    $credential = $request->validate(['registration_token' => 'required|string|size:64']);
                    $pendingRegistration = PendingCustomerRegistration::query()->unexpired()
                        ->where('registration_edit_token_hash', hash('sha256', $credential['registration_token']))
                        ->lockForUpdate()->first();
                    if (! $pendingRegistration || ! CustomerRegistrationService::trustsBrowser($pendingRegistration, $request)) {
                        abort(422, 'This registration is no longer available. Please verify your email again.');
                    }
                }
                $editToken = Str::random(64);
                $data = $this->registrationData($request, $pendingRegistration);

                $values = [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'username' => $data['username'] ?? null,
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password_hash' => Hash::make($data['password']),
                    'registration_edit_token_hash' => hash('sha256', $editToken),
                    'registration_browser_token_hash' => hash('sha256', $browserToken),
                    'email_verification_token' => $pendingRegistration?->email_verification_token,
                    'email_verification_code_hash' => null,
                    'email_verification_expires_at' => $pendingRegistration?->email_verification_expires_at,
                    'registration_recovery_token_hash' => null,
                    'registration_recovery_expires_at' => null,
                    // Editing must not reset the attempt budget or resend cooldown.
                    'email_verification_attempts' => $pendingRegistration?->email_verification_attempts ?? 0,
                ];
                if ($pendingRegistration) {
                    if (strcasecmp($pendingRegistration->email, $values['email']) !== 0) {
                        $values['email_verification_token'] = null;
                        $values['email_verification_expires_at'] = null;
                    }
                    $pendingRegistration->update($values);
                } else {
                    $pendingRegistration = PendingCustomerRegistration::create($values);
                }
                return [$pendingRegistration, $editToken];

            }, 3);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['success' => false, 'message' => 'These details are already in use. Continue with email verification or update your details.'], 422);
        }
        if (! is_array($result)) return $result;
        [$pendingRegistration, $editToken] = $result;

        try {
            $receipt = EmailVerificationController::sendVerificationEmail($pendingRegistration);
        } catch (Throwable $exception) {
            if (! $exception instanceof HttpResponseException) report($exception);
            $throttled = $exception instanceof HttpResponseException ? $exception->getResponse()->getData(true) : [];

            // The pending registration remains recoverable through resend;
            // callers must not retry registration and hit duplicate fields.
            return response()->json([
                'success' => true,
                'message' => $throttled['message'] ?? "Couldn't send the verification email. Try resending.",
                'requires_verification' => true,
                'registration_state' => 'updated',
                'email_delivery_queued' => false,
                'email' => $pendingRegistration->email,
                'registration_token' => $editToken,
                ...app(AuthenticationEmailPolicy::class)->status($pendingRegistration->email),
            ], 201)->withCookie(CustomerRegistrationService::browserCookie($request, $browserToken))->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification email queued.',
            'requires_verification' => true,
            'registration_state' => 'updated',
            'email_delivery_queued' => true,
            'email_delivery_token' => $receipt,
            'email' => $pendingRegistration->email,
            'registration_token' => $editToken,
            ...app(AuthenticationEmailPolicy::class)->status($pendingRegistration->email),
        ], 201)->withCookie(CustomerRegistrationService::browserCookie($request, $browserToken))->header('Cache-Control', 'no-store');
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
            'username' => [
                'nullable',
                'string',
                'max:50',
                ...($checkAvailability ? [Rule::unique('users', 'username')->ignore($legacy?->getKey(), 'user_id'),
                    Rule::unique('pending_customer_registrations', 'username')->ignore($pendingRegistration?->getKey())] : []),
            ],
            'phone' => [
                'nullable',
                ...\App\Support\PhilippineMobile::rules(required: false),
                ...($checkAvailability ? [Rule::unique('users', 'phone')->ignore($legacy?->getKey(), 'user_id'),
                    Rule::unique('pending_customer_registrations', 'phone')->ignore($pendingRegistration?->getKey())] : []),
            ],
            'email' => [
                ...(($existing && strcasecmp($existing->email, $request->input('email')) === 0)
                    ? ['required', 'email', 'max:150'] : AccountEmail::rules()),
            ],
            'password' => 'required|string|min:8|max:100|confirmed',
        ]);
        foreach (['first_name', 'last_name'] as $field) {
            $data[$field] = $existing && $data[$field] === $existing->$field ? $data[$field] : User::normalizeName($data[$field]);
        }
        if ($checkAvailability) AccountEmail::assertAvailable($data['email'], $legacy, false, $pendingRegistration);
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
        ]);

        $identifier = $data['identifier'];
        $normalizedIdentifier = Str::lower($identifier);
        $throttleKey = 'sign-in:'.hash('sha256', $normalizedIdentifier.'|'.$request->ip());
        $ipThrottleKey = 'sign-in-ip:'.hash('sha256', (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)
            || RateLimiter::tooManyAttempts($ipThrottleKey, 25)) {
            $seconds = max(
                RateLimiter::availableIn($throttleKey),
                RateLimiter::availableIn($ipThrottleKey),
            );

            return response()->json([
                'success' => false,
                'message' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429)->header('Retry-After', (string) $seconds);
        }

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
        $lookupColumn = $field === 'email' ? AccountEmail::lookupColumn() : $field;
        $user = User::whereRaw($lookupColumn.' = ?', [$lookupIdentifier])->first();
        $pendingRegistration = $user
            ? null
            : PendingCustomerRegistration::query()->unexpired()->whereRaw($lookupColumn.' = ?', [$lookupIdentifier])->first();

        if ($user?->requiresPasswordSetup()) {
            RateLimiter::clear($throttleKey);

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
            RateLimiter::hit($throttleKey, 60);
            RateLimiter::hit($ipThrottleKey, 60);
            if ($user && in_array($user->role, ['admin', 'staff'], true)) {
                // The attempted account is the subject, never a falsely authenticated actor.
                app(\App\Services\ActivityRecorder::class)->record('sign_in_failed', 'system', 'account',
                    (string) $user->getKey(), 'Staff sign-in', [], false, null, null, null, 'failed');
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($pendingRegistration) {
            RateLimiter::clear($throttleKey);

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
                    (bool) ($data['remember'] ?? true),
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

            RateLimiter::clear($throttleKey);

            return response()->json([
                'success' => true,
                'code' => 'login_confirmation_required',
                'message' => 'Your sign-in email is queued. Enter the code when it arrives.',
                'requires_login_confirmation' => true,
                'email_delivery_queued' => true,
                'email_delivery_token' => $challenge['delivery_token'],
                ...app(AuthenticationEmailPolicy::class)->status($user->email),
                'login_poll_token' => $challenge['poll_token'],
                'email' => $user->email,
            ], 202);
        }

        RateLimiter::clear($throttleKey);
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

    // ── LOGOUT ────────────────────────────────────────────
    public function logout(Request $request)
    {
        $accessToken = $request->user()->currentAccessToken();
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
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
