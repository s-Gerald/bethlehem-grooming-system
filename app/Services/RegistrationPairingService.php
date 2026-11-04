<?php

namespace App\Services;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class RegistrationPairingService
{
    public static function cleared(): array
    {
        return ['confirmation_code_ciphertext' => null, 'confirmation_expires_at' => null,
            'confirmation_attempts' => 0, 'registration_approved_at' => null,
            'registration_completed_at' => null, 'registration_session_ciphertext' => null];
    }

    // Called only after validating the live email link, under its row lock.
    public function confirm(PendingCustomerRegistration $pending, Request $request)
    {
        if (! $pending->confirmation_expires_at) {
            $pending->update([
                'confirmation_code_ciphertext' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                'confirmation_expires_at' => now()->addMinutes(5)->min(RegistrationMagicLinkService::expiresAt($pending)),
                'confirmation_attempts' => 0,
            ]);
        }
        if (! $this->challengeActive($pending)) return $this->expiredCode();
        if (! $request->exists('confirmation_code')) {
            return $this->json(['success' => true, 'requires_registration_confirmation' => true]);
        }
        $code = $request->input('confirmation_code');
        if (! is_string($code) || ! preg_match('/^\d{6}$/D', $code)
            || ! hash_equals($pending->confirmation_code_ciphertext, $code)) {
            $pending->increment('confirmation_attempts');
            if ($pending->confirmation_attempts >= 5) {
                $pending->update(['confirmation_code_ciphertext' => null, 'registration_approved_at' => null]);
                return $this->expiredCode();
            }
            return $this->json(['success' => false, 'code' => 'registration_confirmation_incorrect',
                'message' => 'Incorrect code. Try again.'], 422);
        }
        if (! $pending->registration_approved_at) $pending->update(['registration_approved_at' => now()]);
        return $this->json(['success' => true, 'registration_approved' => true]);
    }

    public function status(Request $request)
    {
        return $this->bound($request, function ($pending) {
            if ($pending->registration_completed_at) return $this->json(['success' => true, 'state' => 'completed']);
            if ($pending->confirmation_expires_at && ! $this->challengeActive($pending)) return $this->expiredCode();
            if ($pending->registration_approved_at) return $this->json(['success' => true, 'state' => 'approved']);
            if ($this->challengeActive($pending)) return $this->json([
                'success' => true, 'state' => 'confirmation_required',
                'confirmation_code' => $pending->confirmation_code_ciphertext,
                'confirmation_expires_at' => $pending->confirmation_expires_at->toIso8601String(),
            ]);
            return $this->json(['success' => true, 'state' => 'pending']);
        });
    }

    public function redeem(Request $request)
    {
        return $this->bound($request, function ($pending) {
            if ($pending->registration_completed_at) {
                // Recover a lost response/cookie using the same token, never issue a second session.
                $plain = $pending->registration_session_ciphertext;
                $token = $plain ? PersonalAccessToken::findToken($plain) : null;
                $user = $token?->tokenable;
                if (! $user instanceof User || $user->role !== 'customer' || ! $user->email_verified_at
                    || strcasecmp($user->email, $pending->email) !== 0 || ! $user->is_active || $user->is_archived
                    || ! app(AccountSessionService::class)->isUsable($token, $user)) return $this->unavailable();
                return $this->json(['success' => true, 'token' => $plain,
                    'user' => $user->only(['user_id', 'first_name', 'last_name', 'email', 'phone', 'role'])]);
            }
            if (! $pending->registration_approved_at || ! $this->challengeActive($pending)) return $this->unavailable();
            $response = app(CustomerRegistrationService::class)->complete($pending, retainPending: true);
            $pending->update([
                ...CustomerRegistrationService::clearedVerification(),
                'confirmation_code_ciphertext' => null, 'password_hash' => '!completed',
                'registration_approved_at' => null,
                'registration_completed_at' => now(),
                'registration_session_ciphertext' => $response->getData(true)['token'],
            ]);
            return $response;
        });
    }

    private function bound(Request $request, callable $action)
    {
        $data = $request->validate(['registration_token' => 'required|string|size:64']);
        return DB::transaction(function () use ($data, $request, $action) {
            $pending = PendingCustomerRegistration::query()
                ->where('registration_edit_token_hash', hash('sha256', $data['registration_token']))->lockForUpdate()->first();
            if (! $pending || ! CustomerRegistrationService::trustsBrowser($pending, $request)) return $this->unavailable();
            if ($pending->retentionExpired()) return $this->json(['success' => false, 'code' => 'registration_expired',
                'message' => 'Registration expired. Start again.'], 422);
            if ($pending->registration_completed_at) {
                if (! $pending->confirmation_expires_at?->isFuture()) return $this->unavailable();
            } elseif (! RegistrationMagicLinkService::expiresAt($pending)?->isFuture()) {
                return $this->json(['success' => false, 'code' => 'registration_link_expired',
                    'message' => 'This link is no longer valid.'], 422);
            }
            return $action($pending);
        }, 3);
    }

    private function challengeActive(PendingCustomerRegistration $pending): bool
    {
        return $pending->confirmation_code_ciphertext && $pending->confirmation_expires_at?->isFuture()
            && $pending->confirmation_attempts < 5;
    }

    private function expiredCode()
    {
        return $this->json(['success' => false, 'code' => 'registration_confirmation_expired',
            'message' => 'Code expired. Request a new one.'], 422);
    }

    private function unavailable()
    {
        return $this->json(['success' => false, 'code' => 'registration_unavailable',
            'message' => 'This link is no longer valid.'], 422);
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }
}
