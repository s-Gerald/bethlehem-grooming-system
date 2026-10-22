<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PendingCustomerRegistration;
use App\Models\PendingStaffAccount;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\SetUpStaffPasswordNotification;
use App\Support\StaffRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PendingStaffAccountService
{
    public function request(
        User $requester,
        string $staffType,
        ?string $staffSubrole,
        string $firstName,
        string $lastName,
        string $email,
    ): array {
        $this->assertAdminIsEligible($requester);
        StaffRole::assertValid($staffType, $staffSubrole);
        $normalizedFirstName = User::normalizeName($firstName);
        $normalizedLastName = User::normalizeName($lastName);
        $normalizedEmail = Str::lower(trim($email));
        $this->assertEmailIsAvailable($normalizedEmail);
        EmailVerificationController::assertMailCanBeDelivered();

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $staff = DB::transaction(function () use (
            $staffType,
            $staffSubrole,
            $normalizedFirstName,
            $normalizedLastName,
            $normalizedEmail,
            $tokenHash,
        ): User {
            PendingStaffAccount::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->delete();

            $staff = User::query()->create([
                'first_name' => $normalizedFirstName,
                'last_name' => $normalizedLastName,
                'username' => null,
                'email' => $normalizedEmail,
                'phone' => null,
                'password_hash' => User::passwordSetupPlaceholder(),
                'role' => 'staff',
                'staff_type' => $staffType,
                'staff_subrole' => $staffSubrole,
                'customer_tier' => 'new',
                'is_active' => false,
                'is_archived' => false,
                'email_verified_at' => null,
            ]);

            PasswordResetRequest::query()->create([
                'user_id' => $staff->getKey(),
                'token_hash' => $tokenHash,
                'expires_at' => now()->addHours(24),
                'last_sent_at' => now(),
            ]);

            return $staff;
        });

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $setupUrl = $frontendUrl
            .'/pages/client/set-up-password.html#token='.rawurlencode($plainToken);

        try {
            AuthenticationMailDelivery::queue($staff, new SetUpStaffPasswordNotification($setupUrl),
                PasswordResetRequest::query()->where('token_hash', $tokenHash)->firstOrFail());
        } catch (Throwable $exception) {
            DB::transaction(function () use ($staff, $tokenHash): void {
                $createdStaff = User::query()
                    ->whereKey($staff->getKey())
                    ->lockForUpdate()
                    ->first();
                $request = PasswordResetRequest::query()
                    ->where('user_id', $staff->getKey())
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if ($createdStaff?->requiresPasswordSetup() && $request) {
                    $createdStaff->delete();
                }
            });
            throw $exception;
        }

        return [
            'staff' => $staff,
            'staff_label' => $this->staffLabel($staffType),
        ];
    }

    private function assertEmailIsAvailable(string $email): void
    {
        if (! $this->emailIsAvailable($email)) {
            throw ValidationException::withMessages([
                'email' => 'That email address is already in use.',
            ]);
        }
    }

    private function emailIsAvailable(string $email): bool
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists()) {
            return false;
        }

        return ! PendingCustomerRegistration::query()->unexpired()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->exists();
    }

    private function assertAdminIsEligible(User $admin): void
    {
        if (! $this->adminIsEligible($admin)) {
            throw ValidationException::withMessages([
                'account' => 'This administrator account cannot create staff accounts.',
            ]);
        }
    }

    private function adminIsEligible(User $admin): bool
    {
        return $admin->role === 'admin' && $admin->is_active && ! $admin->is_archived;
    }

    private function staffLabel(string $staffType): string
    {
        return $staffType === 'clinic' ? 'Clinic Staff' : 'Grooming Receptionist';
    }
}
