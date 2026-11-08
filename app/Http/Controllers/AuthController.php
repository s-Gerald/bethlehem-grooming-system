<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccountEmail;
use App\Services\LoginEmailChallengeService;
use App\Services\CustomerRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Services\AuthenticationEmailPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function cancelRegistration(Request $request, CustomerRegistrationService $registrations)
    {
        return $registrations->cancel($request);
    }

    public function register(Request $request)
    {
        $browserToken = CustomerRegistrationService::browserToken($request);
        try {
            return app(CustomerRegistrationService::class)->start($request, hash('sha256', $browserToken))
                ->withCookie(CustomerRegistrationService::browserCookie($request, $browserToken));
        } catch (HttpResponseException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Customer registration could not start.', ['exception_type' => $exception::class]);
            return response()->json(['success' => false, 'code' => 'email_delivery_failed',
                'message' => "Couldn't queue the verification email. Please try again."], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function finalizeRegistration(Request $request, CustomerRegistrationService $registrations)
    {
        return $registrations->finalize($request);
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

            if ($user?->requiresPasswordSetup()) {
                return response()->json([
                    'success' => false,
                    'code' => 'password_setup_required',
                    'message' => 'Set up your password using the link sent to your email before signing in.',
                ], 403);
            }

            $passwordHash = $user?->password_hash
                ?? '$2y$12$phAn9O3fw5n4sHb8YADptOZicYefzNF1mfQlOwFAKzEQ533R6y4iq';
            $passwordMatches = Hash::check($data['password'], $passwordHash);

            if (! $user || ! $passwordMatches) {
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
