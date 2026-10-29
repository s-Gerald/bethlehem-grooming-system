<?php

namespace App\Http\Controllers;

use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Services\LoginEmailChallengeService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class LoginEmailChallengeController extends Controller
{
    public function resend(Request $request, LoginEmailChallengeService $service)
    {
        $data = $request->validate([
            'poll_token' => ['required', 'string', 'size:64'],
        ]);

        try {
            $issuance = $service->resend($data['poll_token']);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The sign-in email could not be queued. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'A new sign-in email is queued. Please check your inbox shortly.',
            ...$issuance,
        ]);
    }

    public function confirm(Request $request)
    {
        app(\App\Services\AuthenticationEmailPolicy::class)->assertSharedStore();
        $data = $request->validate([
            'poll_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        $pollTokenHash = hash('sha256', $data['poll_token']);
        $attemptKey = 'login-code:'.$pollTokenHash;

        if (RateLimiter::tooManyAttempts($attemptKey, LoginEmailChallengeService::maxAttempts())) {
            return $this->attemptsExceededResponse($attemptKey);
        }

        $ipLock = Cache::lock('login-code-ip-lock:'.hash('sha256', (string) $request->ip()), 30);
        if (! $ipLock->get()) {
            return response()->json(['success' => false, 'code' => 'login_code_busy', 'message' => 'Verification is busy. Try again.', 'retry_after' => 1], 429)
                ->header('Retry-After', '1')->header('Cache-Control', 'no-store');
        }
        try {
        return DB::transaction(function () use ($data, $pollTokenHash, $attemptKey) {
            // Match issuance/resend lock order: account, then challenge/token.
            $userId = LoginEmailChallenge::where('poll_token_hash', $pollTokenHash)->value('user_id');
            if ($userId) User::whereKey($userId)->lockForUpdate()->first();
            $challenge = LoginEmailChallenge::query()
                ->where('poll_token_hash', $pollTokenHash)
                ->lockForUpdate()
                ->first();

            if (RateLimiter::tooManyAttempts($attemptKey, LoginEmailChallengeService::maxAttempts())) {
                return $this->attemptsExceededResponse($attemptKey);
            }

            if (! $challenge) {
                RateLimiter::hit($attemptKey, $this->defaultAttemptDecaySeconds());

                return response()->json([
                    'success' => false,
                    'code' => 'login_code_invalid',
                    'message' => 'The sign-in code is invalid or expired.',
                ], 422);
            }

            if ($expiredResponse = $this->expiredResponse($challenge)) {
                RateLimiter::clear($attemptKey);

                return $expiredResponse;
            }

            if ($challenge->approved_at) {
                $challenge->delete();
                return response()->json(['success' => false, 'code' => 'login_code_invalid',
                    'message' => 'This code has already been used. Sign in again.'], 422);
            }

            $user = $this->usableUser($challenge);
            if (! $user) {
                $challenge->delete();
                RateLimiter::clear($attemptKey);

                return $this->disabledResponse();
            }

            LoginEmailChallengeService::assertAttemptBudget($user);
            if ($challenge->failed_attempts >= LoginEmailChallengeService::maxAttempts()) {
                return $this->attemptsExceededResponse($attemptKey, $challenge);
            }
            $expectedHash = hash_hmac('sha256', $data['code'], $data['poll_token']);
            if (! hash_equals($challenge->token_hash, $expectedHash)) {
                $challenge->increment('failed_attempts');
                foreach (LoginEmailChallengeService::attemptKeys($user) as $key => $limit) {
                    RateLimiter::hit($key, (int) config('authentication.otp_attempt_window', 900));
                }
                RateLimiter::hit($attemptKey, max(
                    1,
                    $challenge->expires_at->getTimestamp() - now()->getTimestamp(),
                ));
                app(\App\Services\AccountSecurityService::class)->failed($user);

                // Return, rather than throwing, so database-backed counters
                // commit with this failed attempt instead of rolling back.
                if ($locked = LoginEmailChallengeService::attemptBudgetResponse($user)) return $locked;

                if (RateLimiter::tooManyAttempts($attemptKey, LoginEmailChallengeService::maxAttempts())) {
                    return $this->attemptsExceededResponse($attemptKey);
                }

                return response()->json([
                    'success' => false,
                    'code' => 'login_code_invalid',
                    'message' => 'Incorrect code. Try again.',
                ], 422);
            }

            // Consume atomically with session issuance. A replay cannot rotate
            // the customer's current session or create duplicate sessions.
            $challenge->delete();

            $response = $this->authenticatedResponse($user, $challenge->remember_me);
            RateLimiter::clear($attemptKey);
            foreach (LoginEmailChallengeService::attemptKeys($user) as $key => $limit) {
                if (! str_starts_with($key, 'login-code-ip:')) RateLimiter::clear($key);
            }
            return $response;
        });
        } finally {
            $ipLock->release();
        }
    }

    public function complete(Request $request)
    {
        $data = $request->validate(['poll_token' => 'required|string|size:64']);
        $pollTokenHash = hash('sha256', $data['poll_token']);

        LoginEmailChallenge::query()
            ->where('user_id', $request->user()->user_id)
            ->where('poll_token_hash', $pollTokenHash)
            ->whereNotNull('approved_at')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Login confirmation completed.',
        ]);
    }

    private function usableUser(LoginEmailChallenge $challenge): ?User
    {
        $user = User::query()
            ->lockForUpdate()
            ->find($challenge->user_id);

        if (! $user
            || ! in_array($user->role, ['customer', 'admin', 'staff'], true)
            || ($user->role === 'customer' && ! $user->email_verified_at)
            || ! $user->is_active
            || $user->is_archived || $user->hasEmailRecoveryHold()) {
            return null;
        }

        return $user;
    }

    private function expiredResponse(LoginEmailChallenge $challenge)
    {
        if (now()->isBefore($challenge->expires_at)) {
            return null;
        }

        $challenge->delete();

        return response()->json([
            'success' => false,
            'message' => 'Code expired. Request a new one.',
            'expired' => true,
        ], 422);
    }

    private function disabledResponse()
    {
        return response()->json([
            'success' => false,
            'code' => 'account_disabled',
            'message' => 'This account cannot complete sign-in. Please contact the clinic.',
        ], 403);
    }

    private function attemptsExceededResponse(string $attemptKey, ?LoginEmailChallenge $challenge = null)
    {
        $seconds = max(1, RateLimiter::availableIn($attemptKey), $challenge ? $challenge->expires_at->timestamp - now()->timestamp : 0);

        return response()->json([
            'success' => false,
            'code' => 'login_code_locked',
            'message' => 'Too many incorrect codes. Sign in again.',
            'retry_after' => $seconds,
        ], 429)->header('Retry-After', (string) $seconds);
    }

    private function defaultAttemptDecaySeconds(): int
    {
        return max(
            60,
            (int) config('authentication.otp_minutes', 10) * 60,
        );
    }

    private function authenticatedResponse(User $user, bool $rememberMe)
    {
        $token = app(\App\Services\AccountSessionService::class)->issue($user, $rememberMe);

        $response = response()->json([
            'success' => true,
            'message' => 'Sign-in confirmed successfully.',
            'approved' => true,
            'session_established' => true,
            'token' => $token,
            'remember_me' => $rememberMe,
            'security_incident_id' => app(\App\Services\AccountSecurityService::class)->unreviewedId($user),
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
        ]);
        $devices = app(\App\Services\RememberedDeviceService::class);
        // This method runs inside the OTP consumption transaction.
        return $user->role === 'customer' && $rememberMe
            ? $response->withCookie($devices->issue($user, $token))
            : $response->withCookie(\App\Http\Middleware\UseAccountSessionCookie::cookie(
                request(), $devices::COOKIE, '', now()->subYear()));
    }
}
