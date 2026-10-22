<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PendingCustomerRegistration;
use App\Models\PrivilegedCredentialChange;
use App\Models\User;
use App\Notifications\ConfirmPrivilegedCredentialChangeCodeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivilegedCredentialChangeService
{
    public const MAX_CODE_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function request(
        User $requester,
        User $target,
        ?string $requestedUsername,
        ?string $newPassword,
    ): array {
        $this->assertRequesterAndTargetAreEligible($requester, $target);

        $newUsername = filled($requestedUsername)
            ? trim((string) $requestedUsername)
            : null;
        if ($newUsername === $target->username) {
            $newUsername = null;
        }

        $changesPassword = filled($newPassword);
        if ($newUsername === null && ! $changesPassword) {
            throw ValidationException::withMessages([
                'credentials' => 'Enter a different username or a new password.',
            ]);
        }

        if ($newUsername !== null) {
            $this->assertUsernameIsAvailable($newUsername, $target->getKey());
        }

        if ($changesPassword && Hash::check((string) $newPassword, $target->password_hash)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password that is different from the current password.',
            ]);
        }

        EmailVerificationController::assertMailCanBeDelivered();

        return DB::transaction(function () use ($requester, $target, $newUsername, $newPassword, $changesPassword): array {
            $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRequesterAndTargetAreEligible($requester, $target);
            $plainCode = $this->generateCode();
            $change = PrivilegedCredentialChange::query()->create([
                'requested_by_user_id' => $requester->user_id,
                'target_user_id' => $target->user_id,
                'target_role' => $target->role,
                'new_username' => $newUsername,
                'new_password_hash' => $changesPassword
                    ? Hash::make((string) $newPassword)
                    : null,
                'changes_password' => $changesPassword,
                'code_hash' => Hash::make($plainCode),
                'failed_attempts' => 0,
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes($this->codeTtlMinutes()),
            ]);

            $receipt = $this->sendCode($requester, $target, $change, $plainCode);

            PrivilegedCredentialChange::query()
                ->where('target_user_id', $target->user_id)
                ->whereNull('confirmed_at')
                ->whereKeyNot($change->getKey())
                ->delete();

            return $this->challengePayload($change, $requester, $target) + ['email_delivery_token' => $receipt];
        }, 3);
    }

    public function confirm(
        User $requester,
        PrivilegedCredentialChange $requestedChange,
        string $plainCode,
    ): array {
        return DB::transaction(function () use ($requester, $requestedChange, $plainCode): array {
            $change = PrivilegedCredentialChange::query()
                ->whereKey($requestedChange->getKey())
                ->whereNull('confirmed_at')
                ->lockForUpdate()
                ->first();

            if (! $change || $change->requested_by_user_id !== $requester->user_id) {
                return ['status' => 'invalid'];
            }

            if (now()->greaterThanOrEqualTo($change->expires_at)) {
                return ['status' => 'expired'];
            }

            if (! $change->code_hash || $change->failed_attempts >= self::MAX_CODE_ATTEMPTS) {
                return ['status' => 'locked'];
            }

            if (! Hash::check($plainCode, $change->code_hash)) {
                $failedAttempts = $change->failed_attempts + 1;
                $locked = $failedAttempts >= self::MAX_CODE_ATTEMPTS;
                $change->update([
                    'failed_attempts' => $failedAttempts,
                ]);

                return [
                    'status' => $locked ? 'locked' : 'invalid_code',
                    'attempts_remaining' => max(0, self::MAX_CODE_ATTEMPTS - $failedAttempts),
                ];
            }

            $lockedRequester = User::query()
                ->whereKey($change->requested_by_user_id)
                ->lockForUpdate()
                ->first();
            $target = $change->target_user_id === $change->requested_by_user_id
                ? $lockedRequester
                : User::query()
                    ->whereKey($change->target_user_id)
                    ->lockForUpdate()
                    ->first();

            if (! $lockedRequester || ! $target || ! $this->usersAreStillEligible(
                $lockedRequester,
                $target,
                $change->target_role,
            )) {
                $change->delete();

                return ['status' => 'invalid'];
            }

            if ($change->new_username !== null
                && ! $this->usernameIsAvailable($change->new_username, $target->getKey(), true)) {
                return [
                    'status' => 'username_unavailable',
                    'message' => 'That username became unavailable. Return to Security settings and choose another one.',
                ];
            }

            $changedFields = $this->changedFields($change);
            if ($change->new_username !== null) {
                $target->username = $change->new_username;
            }
            if ($change->changes_password) {
                $target->password_hash = $change->new_password_hash;
            }
            $target->save();

            $staffSelfServiceChange = $target->is($lockedRequester)
                && $lockedRequester->role === 'staff';
            if (! $staffSelfServiceChange) {
                $target->tokens()->delete();
            }

            $change->update([
                'new_password_hash' => null,
                'code_hash' => null,
                'confirmed_at' => now(),
            ]);

            return [
                'status' => 'confirmed',
                'purpose' => $this->purposeLabel($change),
                'target_name' => $this->displayName($target),
                'target_role' => $target->role,
                'changes' => $changedFields,
                'requires_reauthentication' => $target->is($lockedRequester)
                    && ! $staffSelfServiceChange,
            ];
        });
    }

    public function resend(
        User $requester,
        PrivilegedCredentialChange $requestedChange,
    ): array {
        EmailVerificationController::assertMailCanBeDelivered();

        return DB::transaction(function () use ($requester, $requestedChange): array {
            $change = PrivilegedCredentialChange::query()
                ->whereKey($requestedChange->getKey())
                ->whereNull('confirmed_at')
                ->lockForUpdate()
                ->first();

            if (! $change || $change->requested_by_user_id !== $requester->user_id) {
                return ['status' => 'invalid'];
            }

            $target = User::query()->whereKey($change->target_user_id)->first();
            if (! $target || ! $this->usersAreStillEligible($requester, $target, $change->target_role)) {
                $change->delete();

                return ['status' => 'invalid'];
            }

            $availableAt = $change->last_sent_at?->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
            if ($availableAt && now()->isBefore($availableAt)) {
                return [
                    'status' => 'cooldown',
                    'retry_after' => max(1, now()->diffInSeconds($availableAt)),
                ];
            }

            $attempts = now()->isBefore($change->expires_at) ? $change->failed_attempts : 0;
            if ($attempts >= self::MAX_CODE_ATTEMPTS) {
                return ['status' => 'cooldown', 'retry_after' => max(1, $change->expires_at->timestamp - now()->timestamp)];
            }
            do {
                $plainCode = $this->generateCode();
            } while ($change->code_hash && Hash::check($plainCode, $change->code_hash));
            $change->update([
                'code_hash' => Hash::make($plainCode),
                'failed_attempts' => $attempts,
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes($this->codeTtlMinutes()),
            ]);
            $receipt = $this->sendCode($requester, $target, $change, $plainCode);

            return ['status' => 'resent', 'email_delivery_token' => $receipt] + $this->challengePayload($change, $requester, $target);
        });
    }

    private function sendCode(
        User $requester,
        User $target,
        PrivilegedCredentialChange $change,
        string $plainCode,
    ): string {
        return AuthenticationMailDelivery::queue($requester, new ConfirmPrivilegedCredentialChangeCodeNotification(
            $plainCode,
            $this->purposeLabel($change),
            $this->displayName($target),
            $target->role === 'admin',
        ), $change, 'code_hash');
    }

    private function challengePayload(
        PrivilegedCredentialChange $change,
        User $requester,
        User $target,
    ): array {
        return [
            'change_id' => $change->getKey(),
            'purpose' => $this->purposeLabel($change),
            'target_name' => $this->displayName($target),
            'confirmation_email' => $this->maskEmail($requester->email),
            'expires_at' => $change->expires_at->toIso8601String(),
            'resend_after' => max(0, ($change->last_sent_at?->timestamp ?? 0) + self::RESEND_COOLDOWN_SECONDS - now()->timestamp),
        ];
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function codeTtlMinutes(): int
    {
        return max(
            1,
            (int) config('app.privileged_credential_change_code_ttl_minutes', 10),
        );
    }

    private function assertRequesterAndTargetAreEligible(User $requester, User $target): void
    {
        if (! $this->usersAreStillEligible($requester, $target, $target->role)) {
            throw ValidationException::withMessages([
                'account' => 'This account is not eligible for a credential change.',
            ]);
        }
    }

    private function usersAreStillEligible(User $requester, User $target, string $targetRole): bool
    {
        $requesterIsUsableAdmin = $requester->role === 'admin'
            && $requester->is_active
            && ! $requester->is_archived;
        $requesterIsUsableStaff = $requester->role === 'staff'
            && $requester->is_active
            && ! $requester->is_archived;
        $targetIsAllowed = $target->role === $targetRole
            && in_array($target->role, ['admin', 'staff'], true)
            && ! $target->is_archived;
        $selfChangeIsValid = $target->role !== 'admin' || $target->is($requester);
        $staffSelfChangeIsValid = $requesterIsUsableStaff
            && $target->role === 'staff'
            && $target->is($requester);

        return $targetIsAllowed && (
            ($requesterIsUsableAdmin && $selfChangeIsValid)
            || $staffSelfChangeIsValid
        );
    }

    private function assertUsernameIsAvailable(string $username, int $targetUserId): void
    {
        if (! $this->usernameIsAvailable($username, $targetUserId)) {
            throw ValidationException::withMessages([
                'username' => 'That username is already in use.',
            ]);
        }
    }

    private function usernameIsAvailable(
        string $username,
        int $targetUserId,
        bool $lockForUpdate = false,
    ): bool {
        $normalized = Str::lower($username);
        $userQuery = User::query()
            ->whereRaw('LOWER(username) = ?', [$normalized])
            ->whereKeyNot($targetUserId);
        if ($lockForUpdate) {
            $userQuery->lockForUpdate();
        }

        if ($userQuery->exists()) {
            return false;
        }

        if (! Schema::hasTable('pending_customer_registrations')) {
            return true;
        }

        $pendingQuery = PendingCustomerRegistration::query()->unexpired()
            ->whereRaw('LOWER(username) = ?', [$normalized]);
        if ($lockForUpdate) {
            $pendingQuery->lockForUpdate();
        }

        return ! $pendingQuery->exists();
    }

    private function changedFields(PrivilegedCredentialChange $change): array
    {
        return array_values(array_filter([
            $change->new_username !== null ? 'username' : null,
            $change->changes_password ? 'password' : null,
        ]));
    }

    private function purposeLabel(PrivilegedCredentialChange $change): string
    {
        $account = $change->target_role === 'admin' ? 'Admin' : 'Staff';
        $fields = $this->changedFields($change);
        $credential = count($fields) === 2
            ? 'username and password'
            : $fields[0];

        return "{$account} {$credential} change";
    }

    private function displayName(User $user): string
    {
        $name = trim("{$user->first_name} {$user->last_name}");

        return $name !== '' ? $name : ucfirst($user->role).' account';
    }

    private function maskEmail(string $email): string
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return 'the administrator email';
        }

        $visible = mb_substr($localPart, 0, 1);
        $maskedLength = max(3, mb_strlen($localPart) - 1);

        return $visible.str_repeat('*', $maskedLength).'@'.$domain;
    }
}
