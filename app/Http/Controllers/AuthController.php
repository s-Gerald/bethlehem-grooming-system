<?php

namespace App\Http\Controllers;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Services\LoginEmailChallengeService;
use App\Services\CustomerRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
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
        PendingCustomerRegistration::pruneExpired();

        $phone = $request->input('phone');
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'username' => filled($request->input('username'))
                ? trim((string) $request->input('username'))
                : null,
            'phone' => is_string($phone) && trim($phone) === '' ? null : $phone,
        ]);

        $request->validate(['email' => 'required|email|max:150']);

        try {
            $result = DB::transaction(function () use ($request) {
                $identifiers = function ($query) use ($request) {
                    $query->where('email', $request->input('email'));
                    if ($request->filled('phone')) $query->orWhere('phone', $request->input('phone'));
                    if ($request->filled('username')) $query->orWhere('username', $request->input('username'));
                };
                $pendingMatches = PendingCustomerRegistration::query()->where($identifiers)->lockForUpdate()->get(['id', 'email']);
                $userMatches = User::query()->where($identifiers)->lockForUpdate()->get(['user_id', 'email']);
                $sameEmail = fn ($registrant) => Str::lower($registrant->email) === $request->input('email');
                // An email alone cannot disclose recovery eligibility or change stored details.
                if (! $request->isMethod('put')
                    && ($userMatches->contains($sameEmail) || $pendingMatches->contains($sameEmail))) {
                    return response()->json([
                        'success' => true, 'requires_verification' => true,
                        'message' => 'Continue with email verification, or sign in if your account is ready.',
                        'resend_after' => 0,
                    ], 202);
                }
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
                    if ($request->input('email') !== $registrant->email) {
                        abort(422, 'Use the email address you verified.');
                    }
                    $pendingRegistration = $legacy
                        ? PendingCustomerRegistration::query()->where('email', $legacy->email)->lockForUpdate()->first()
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
                    if (! $pendingRegistration) {
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
                    'email_verification_token' => null,
                    'email_verification_code_hash' => null,
                    'email_verification_expires_at' => $pendingRegistration?->email_verification_expires_at,
                    'registration_recovery_token_hash' => null,
                    'registration_recovery_expires_at' => null,
                    // Editing must not reset the attempt budget or resend cooldown.
                    'email_verification_attempts' => $pendingRegistration?->email_verification_attempts ?? 0,
                ];
                if ($pendingRegistration) {
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
            report($exception);

            // The pending registration remains recoverable through resend;
            // callers must not retry registration and hit duplicate fields.
            return response()->json([
                'success' => true,
                'message' => 'Registration saved, but the verification email could not be queued. Please use Resend Code.',
                'requires_verification' => true,
                'email_delivery_queued' => false,
                'email' => $pendingRegistration->email,
                'registration_token' => $editToken,
                'resend_after' => max(0, ($pendingRegistration->fresh()?->email_verification_last_sent_at?->timestamp ?? 0) + 45 - now()->timestamp),
            ], 201);
        }

        return response()->json([
            'success' => true,
            'message' => 'Please check your email to complete account registration.',
            'requires_verification' => true,
            'email_delivery_queued' => true,
            'email_delivery_token' => $receipt,
            'email' => $pendingRegistration->email,
            'registration_token' => $editToken,
            'resend_after' => max(0, ($pendingRegistration->fresh()?->email_verification_last_sent_at?->timestamp ?? 0) + 45 - now()->timestamp),
        ], 201);
    }

    private function registrationData(Request $request, ?PendingCustomerRegistration $pendingRegistration = null, ?User $legacy = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z\s\'\-]+$/'],
            'last_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z\s\'\-]+$/'],
            'username' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'username')->ignore($legacy?->getKey(), 'user_id'),
                Rule::unique('pending_customer_registrations', 'username')->ignore($pendingRegistration?->getKey()),
            ],
            'phone' => [
                'nullable',
                'string',
                'regex:/^09\d{9}$/',
                Rule::unique('users', 'phone')->ignore($legacy?->getKey(), 'user_id'),
                Rule::unique('pending_customer_registrations', 'phone')->ignore($pendingRegistration?->getKey()),
            ],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($legacy?->getKey(), 'user_id'),
                Rule::unique('pending_customer_registrations', 'email')->ignore($pendingRegistration?->getKey()),
            ],
            'password' => 'required|string|min:8|max:100|confirmed',
        ]);
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
        } elseif (preg_match('/^(09|\+639)\d{9}$/', $identifier)) {
            $field = 'phone';
        } else {
            $field = 'username';
        }

        $lookupIdentifier = match (true) {
            $field === 'email' => $normalizedIdentifier,
            $field === 'phone' && str_starts_with($identifier, '+63') => '0'.substr($identifier, 3),
            default => $identifier,
        };
        $user = User::where($field, $lookupIdentifier)->first();
        $pendingRegistration = $user
            ? null
            : PendingCustomerRegistration::query()->unexpired()->where($field, $lookupIdentifier)->first();

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

        if (! $user->is_active || $user->is_archived) {
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
                'message' => 'Please verify your email address before signing in. Check your inbox for the verification code.',
                'email_not_verified' => true,
                'email' => $user->email,
            ], 403);
        }

        if (in_array($user->role, ['customer', 'admin', 'staff'], true)) {
            $confirmationThrottleKey = 'login-confirmation:'
                .hash('sha256', $user->user_id.'|'.$request->ip());
            if (RateLimiter::tooManyAttempts($confirmationThrottleKey, 3)) {
                $seconds = RateLimiter::availableIn($confirmationThrottleKey);

                return response()->json([
                    'success' => false,
                    'message' => "Too many login confirmation emails requested. Try again in {$seconds} seconds.",
                    'retry_after' => $seconds,
                ], 429)->header('Retry-After', (string) $seconds);
            }

            try {
                $challenge = $loginChallenges->send(
                    $user,
                    (bool) ($data['remember'] ?? true),
                );
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'success' => false,
                    'message' => 'Your credentials were confirmed, but the login confirmation email could not be queued. Please try again.',
                ], 503);
            }

            RateLimiter::hit($confirmationThrottleKey, 600);
            RateLimiter::clear($throttleKey);

            return response()->json([
                'success' => true,
                'code' => 'login_confirmation_required',
                'message' => 'Your sign-in email is queued. Enter the code when it arrives.',
                'requires_login_confirmation' => true,
                'email_delivery_queued' => true,
                'email_delivery_token' => $challenge['delivery_token'],
                'resend_after' => $challenge['resend_after'],
                'login_poll_token' => $challenge['poll_token'],
                'email' => $user->email,
            ], 202);
        }

        RateLimiter::clear($throttleKey);
        $tokenName = 'admin_token';
        $token = $user->createToken($tokenName)->plainTextToken;

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
