<?php

namespace App\Support;

use App\Models\PendingCustomerRegistration;
use App\Models\PendingStaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AccountUsername
{
    public static function rules(string $presence = 'required'): array
    {
        return [$presence, 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z][A-Za-z0-9._-]{2,49}$/'];
    }

    public static function isAvailable(string $username, int $userId, bool $lockForUpdate = false): bool
    {
        $normalized = Str::lower($username);
        $users = User::query()->whereRaw('LOWER(username) = ?', [$normalized])->whereKeyNot($userId);
        if ($lockForUpdate) {
            $users->lockForUpdate();
        }
        if ($users->exists()) {
            return false;
        }

        if (! Schema::hasTable('pending_customer_registrations')) {
            return true;
        }
        $pending = PendingCustomerRegistration::query()->unexpired()->whereRaw('LOWER(username) = ?', [$normalized]);
        if ($lockForUpdate) {
            $pending->lockForUpdate();
        }

        return ! $pending->exists();
    }

    public static function isAvailableToStaff(string $username, User $staff, bool $lockForUpdate = false): bool
    {
        if (! self::isAvailable($username, $staff->getKey(), $lockForUpdate)) {
            return false;
        }
        if (! Schema::hasTable('pending_staff_accounts')) {
            return true;
        }

        // Keep the same legacy reservation exception used by staff password setup.
        $pending = PendingStaffAccount::query()->whereRaw('LOWER(username) = ?', [Str::lower($username)])
            ->whereRaw('LOWER(email) <> ?', [Str::lower($staff->email)]);
        if ($lockForUpdate) {
            $pending->lockForUpdate();
        }

        return ! $pending->exists();
    }
}
