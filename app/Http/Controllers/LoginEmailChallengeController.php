<?php

namespace App\Http\Controllers;

use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Services\LoginEmailChallengeService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class LoginEmailChallengeController extends Controller
{
    public function resend(Request $request, LoginEmailChallengeService $service)
    {
        $data = $request->validate([
            'poll_token' => ['required', 'string', 'size:64'],
        ]);

        try {
            $receipt = $service->resend($data['poll_token']);
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
            'email_delivery_token' => $receipt,
            'resend_after' => LoginEmailChallengeService::resendAfter(LoginEmailChallenge::query()
                ->where('poll_token_hash', hash('sha256', $data['poll_token']))->first()),
        ]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate([
            'poll_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        $pollTokenHash = hash('sha256', $data['poll_token']);
        $attemptKey = 'login-code:'.$pollTokenHash;

        if (RateLimiter::tooManyAttempts($attemptKey, LoginEmailChallengeService::MAX_ATTEMPTS)) {
            return $this->attemptsExceededResponse($attemptKey);
        }

        return DB::transaction(function () use ($data, $pollTokenHash, $attemptKey) {
            $challenge = LoginEmailChallenge::query()
                ->where('poll_token_hash', $pollTokenHash)
                ->lockForUpdate()
                ->first();

            if (RateLimiter::tooManyAttempts($attemptKey, LoginEmailChallengeService::MAX_ATTEMPTS)) {
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

            $user = $this->usableUser($challenge);
            if (! $user) {
                $challenge->delete();
                RateLimiter::clear($attemptKey);

                return $this->disabledResponse();
            }

            $expectedHash = hash_hmac('sha256', $data['code'], $data['poll_token']);
            if (! hash_equals($challenge->token_hash, $expectedHash)) {
                RateLimiter::hit($attemptKey, max(
                    1,
                    $challenge->expires_at->getTimestamp() - now()->getTimestamp(),
                ));

                if (RateLimiter::tooManyAttempts($attemptKey, LoginEmailChallengeService::MAX_ATTEMPTS)) {
                    return $this->attemptsExceededResponse($attemptKey);
                }

                return response()->json([
                    'success' => false,
                    'code' => 'login_code_invalid',
                    'message' => 'The sign-in code is incorrect.',
                ], 422);
            }

            RateLimiter::clear($attemptKey);

            if (! $challenge->approved_at) {
                $challenge->update(['approved_at' => now()]);
            }

            // Keep the challenge until the browser confirms that it safely
            // stored this session. A lost response can be retried with the
            // same code without stranding the customer.
            return $this->authenticatedResponse($user, $challenge->remember_me);
        });
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
            || $user->is_archived) {
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
            'message' => 'This sign-in confirmation has expired. Please sign in again.',
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

    private function attemptsExceededResponse(string $attemptKey)
    {
        $seconds = max(1, RateLimiter::availableIn($attemptKey));

        return response()->json([
            'success' => false,
            'code' => 'login_code_locked',
            'message' => 'Too many incorrect codes. Sign in again to request a new code.',
            'retry_after' => $seconds,
        ], 429)->header('Retry-After', (string) $seconds);
    }

    private function defaultAttemptDecaySeconds(): int
    {
        return max(
            60,
            (int) config('app.login_confirmation_ttl_minutes', 15) * 60,
        );
    }

    private function authenticatedResponse(User $user, bool $rememberMe)
    {
        $tokenName = in_array($user->role, ['admin', 'staff'], true)
            ? 'admin_token'
            : 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Sign-in confirmed successfully.',
            'approved' => true,
            'session_established' => true,
            'token' => $token,
            'remember_me' => $rememberMe,
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
            ],
        ]);
    }
}
