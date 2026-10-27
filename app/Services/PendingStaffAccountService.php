<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PendingStaffAccount;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\SetUpStaffPasswordNotification;
use App\Support\StaffRole;
use App\Support\AccountEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
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
        $normalizedEmail = AccountEmail::normalize($email);
        Validator::make(['email' => $normalizedEmail], ['email' => AccountEmail::rules()])->validate();
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
                ->whereRaw('LOWER(email) = ?', [Str::lower($normalizedEmail)])
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

    public static function emailRules(): array
    {
        return AccountEmail::rules();
    }

    public function correctEmail(User $requester, User $staff, string $email, string $password): User
    {
        $email = AccountEmail::normalize($email);
        Validator::make(['email' => $email], ['email' => self::emailRules()])->validate();

        try {
            return DB::transaction(function () use ($requester, $staff, $email, $password): User {
                $admin = User::query()->whereKey($requester->getKey())->lockForUpdate()->first();
                abort_unless($admin && $this->adminIsEligible($admin), 403);
                if (! Hash::check($password, $admin->password_hash)) {
                    throw ValidationException::withMessages(['current_password' => 'The admin password is incorrect.']);
                }

                // Match setup completion/resend: lock the issuance before the account.
                PasswordResetRequest::query()->where('user_id', $staff->getKey())->lockForUpdate()->first();
                $target = User::query()->whereKey($staff->getKey())->lockForUpdate()->first();
                abort_if(! $target || $target->role !== 'staff' || $target->is_archived || $target->account_deleted_at !== null, 404);
                if ($target->is_active || ! $target->requiresPasswordSetup()) {
                    throw ValidationException::withMessages(['account' => 'This account no longer requires initial setup.']);
                }
                if (strcasecmp($email, $target->email) === 0) {
                    throw ValidationException::withMessages(['email' => 'Enter a different email address.']);
                }
                $this->assertEmailIsAvailable($email, $target);
                $target->email = $email;
                $target->save();

                $result = app(PasswordResetService::class)->resendStaffSetupLink($target);
                if ($result['status'] !== 'sent') {
                    throw ValidationException::withMessages(['account' => 'This setup invitation is no longer available.']);
                }

                return $target->load('passwordResetRequest');
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email is already in use.']);
        }
    }

    private function assertEmailIsAvailable(string $email, ?User $except = null): void
    {
        AccountEmail::assertAvailable($email, $except, $except !== null);
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
