<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
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

        try {
            $passwordResets->sendCode($data['email']);
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'If eligible, a new code was requested. Check your email.',
            'email' => $passwordResets->maskEmail($data['email']),
            'resend_after' => PasswordResetService::COOLDOWN_SECONDS,
        ], 202);
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
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required', 'string'],
        ]);
        $result = $passwordResets->reset($data['token'], $data['password']);

        if ($result['status'] === 'same_password') {
            return response()->json([
                'success' => false,
                'message' => 'Choose a password that is different from the current password.',
            ], 422);
        }

        if ($result['status'] === 'expired') {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'Invalid or expired verification code.',
            ], 422);
        }

        if ($result['status'] !== 'reset') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code.',
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
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required', 'string'],
        ]);
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
            'token' => $user->createToken('admin_token')->plainTextToken,
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
