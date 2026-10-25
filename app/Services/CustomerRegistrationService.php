<?php

namespace App\Services;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerRegistrationService
{
    public static function recoverableUser(User $user): bool
    {
        return $user->role === 'customer' && ! $user->email_verified_at
            && $user->is_active && ! $user->is_archived && ! $user->account_deleted_at;
    }

    // Called only after OTP validation while holding the registrant's row lock.
    public function authorizeReview(User|PendingCustomerRegistration $registrant)
    {
        $grant = Str::random(64);
        $values = [
            ...self::clearedVerification(),
            'registration_recovery_token_hash' => hash('sha256', $grant),
            'registration_recovery_expires_at' => now()->addMinutes(10),
        ];
        if ($registrant instanceof PendingCustomerRegistration) {
            $values['registration_edit_token_hash'] = null;
        }
        $registrant->update($values);

        return response()->json([
            'success' => true,
            'requires_registration_review' => true,
            'registration_recovery_token' => $grant,
            'details' => $registrant->only(['first_name', 'last_name', 'username', 'email', 'phone']),
            'message' => 'Email confirmed. Review your details and choose a password to complete registration.',
        ])->header('Cache-Control', 'no-store');
    }

    // Caller holds the registrant row lock and transaction, including session creation.
    public function complete(User|PendingCustomerRegistration $registrant, ?array $details = null)
    {
        if ($registrant instanceof PendingCustomerRegistration) {
            if ($registrant->retentionExpired()) $this->unavailable();
            $values = $details ?? $registrant->only(['first_name', 'last_name', 'username', 'email', 'phone', 'password_hash']);
            $conflict = User::query()->where(function ($query) use ($values) {
                $query->where('email', $values['email']);
                if ($values['phone'] ?? null) $query->orWhere('phone', $values['phone']);
                if ($values['username'] ?? null) $query->orWhere('username', $values['username']);
            })->lockForUpdate()->exists();
            if ($conflict) $this->unavailable();
            $user = User::create([
                ...$values, 'role' => 'customer', 'customer_tier' => 'new',
                'is_active' => true, 'is_archived' => false, 'email_verified_at' => now(),
            ]);
            $registrant->delete();
        } else {
            if (! self::recoverableUser($registrant)) $this->unavailable();
            $user = $registrant;
            if ($details && ($details['phone'] ?? null) !== $user->phone) {
                $details['phone_verified_at'] = null;
            }
            $user->update([
                ...($details ?? []), ...self::clearedVerification(),
                'email_verified_at' => now(), 'registration_recovery_token_hash' => null,
                'registration_recovery_expires_at' => null,
            ]);
            // Preserve the existing user ID, pets, bookings, tier, and history.
            $user->tokens()->delete();
            PendingCustomerRegistration::query()->where('email', $user->email)->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Email verified and account registered successfully.',
            'token' => app(AccountSessionService::class)->issue($user),
            'user' => $user->only(['user_id', 'first_name', 'last_name', 'email', 'phone', 'role']),
        ])->header('Cache-Control', 'no-store');
    }

    public static function clearedVerification(): array
    {
        return [
            'email_verification_token' => null, 'email_verification_expires_at' => null,
            'email_verification_code_hash' => null, 'email_verification_last_sent_at' => null,
            'email_verification_attempts' => 0,
        ];
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages([
            'registration' => 'These registration details are no longer available. Please verify your email again or update your details.',
        ]);
    }
}
