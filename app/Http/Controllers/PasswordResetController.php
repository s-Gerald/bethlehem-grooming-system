<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Rules\AccountPassword;
use Throwable;

class PasswordResetController extends Controller
{
    public function requestCode(Request $request, PasswordResetService $passwordResets)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
        ]);

        $publicStatus = app(\App\Services\AuthenticationEmailPolicy::class)->publicRecoveryRequest($data['email']);
        try {
            $result = $passwordResets->sendCode($data['email']);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['success' => false, 'code' => 'email_delivery_failed',
                'message' => "Couldn't request a code. Try again."], 503)->header('Cache-Control', 'no-store');
        }

        if ($result['status'] === 'limited') {
            return response()->json(['success' => false, 'code' => 'email_rate_limited',
                'message' => 'Too many requests. Please wait before trying again.',
                'retry_after' => $result['retry_after']], 429)->header('Retry-After', (string) $result['retry_after']);
        }
        if ($result['status'] !== 'queued') {
            $message = $result['status'] === 'account_not_found' ? 'No account found with this email.'
                : "Password reset isn't available for this account.";
            return response()->json(['success' => false, 'code' => $result['status'],
                'message' => $message, 'errors' => ['email' => [$message]], ...$publicStatus], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'success' => true,
            'message' => 'Check your email for a verification code.',
            'email' => $passwordResets->maskEmail($data['email']),
            ...$publicStatus,
            'expires_in' => $passwordResets->ttlMinutes() * 60,
        ], 202)->header('Cache-Control', 'no-store');
    }

    public function verifyCode(Request $request, PasswordResetService $passwordResets)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
        ]);
        $code = trim((string) $request->input('code'));
        if (! preg_match('/^[0-9]{6}$/', $code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code.',
            ], 422);
        }
        $result = $passwordResets->verifyCode($data['email'], $code);

        if ($result['status'] !== 'valid') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'token' => $result['token'],
            'expires_in' => $result['expires_in'],
            'password_min_length' => $result['password_min_length'],
        ]);
    }

    public function verifyStaffSetupLink(Request $request, PasswordResetService $passwordResets)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);
        $result = $passwordResets->inspectStaffSetup($data['token']);

        if ($result['status'] !== 'valid') {
            return response()->json([
                'success' => false,
                'expired' => $result['status'] === 'expired',
                'message' => $result['status'] === 'expired'
                    ? 'This setup link has expired. Request a new link to finish setting up your account.'
                    : 'This setup link is no longer valid.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'email' => $result['email'],
        ]);
    }

    public function requestNewStaffSetupLink(Request $request, PasswordResetService $passwordResets)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        try {
            $result = $passwordResets->resendExpiredStaffSetupLink($data['token']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The setup email could not be queued. Please try again.',
            ], 503);
        }

        if ($result['status'] !== 'sent') {
            return response()->json([
                'success' => false,
                'message' => 'This setup link is no longer valid.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'A new setup link has been sent to your email.',
        ], 202);
    }

    public function reset(Request $request, PasswordResetService $passwordResets)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => [
                'required',
                'string',
            ],
            'password_confirmation' => ['bail', 'required', 'string', 'same:password'],
        ], AccountPassword::messages());
        $result = $passwordResets->reset($data['token'], $data['password']);

        if ($result['status'] === 'same_password') {
            return response()->json([
                'success' => false,
                'message' => 'Choose a different password.',
                'errors' => ['password' => ['Choose a different password.']],
            ], 422);
        }

        if (in_array($result['status'], ['expired', 'invalid'], true)) {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'Your reset session expired. Request a new code.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully.',
        ]);
    }

    public function completeStaffSetup(Request $request, PasswordResetService $passwordResets)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'username' => ['nullable', 'string'],
            'password' => [
                'required',
                'string',
            ],
            'password_confirmation' => ['bail', 'required', 'string', 'same:password'],
        ], AccountPassword::messages());
        $result = $passwordResets->completeStaffSetup($data['token'], $data['password'], $data['username'] ?? null);

        if ($result['status'] === 'expired') {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'This setup link has expired. Request a new link to finish setting up your account.',
            ], 422);
        }

        if ($result['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'This setup link is no longer valid.',
            ], 422);
        }

        $user = $result['user'];

        return response()->json([
            'success' => true,
            'message' => 'Your staff account is ready.',
            'completed_setup' => true,
            'token' => app(\App\Services\AccountSessionService::class)->issue($user),
            'user' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
