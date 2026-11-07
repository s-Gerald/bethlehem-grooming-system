<?php

namespace App\Support;

use App\Models\PendingStaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AccountUsername
{
    public static function rules(string $presence = 'required', ?string $unchanged = null): array
    {
        return ['bail', $presence, 'string', function ($attribute, $value, $fail) use ($unchanged) {
            if ($unchanged !== null && $value === $unchanged) return;
            if (strlen($value) < 3 || strlen($value) > 30) $fail('Use 3–30 characters.');
            elseif (preg_match('/[^A-Za-z0-9._-]/', $value)) $fail('Use letters, numbers, periods, underscores, or hyphens only.');
            elseif (! preg_match('/\A[A-Za-z]/', $value)) $fail('Begin with a letter.');
            elseif (! preg_match('/[A-Za-z0-9]\z/', $value)) $fail('End with a letter or number.');
        }];
    }

    public static function isAvailable(string $username, int $userId, bool $lockForUpdate = false, ?string $staffEmail = null): bool
    {
        $normalized = Str::lower($username);
        $users = User::query()->whereRaw('LOWER(username) = ?', [$normalized])->whereKeyNot($userId);
        if ($lockForUpdate) {
            $users->lockForUpdate();
        }
        if ($users->exists()) {
            return false;
        }

        if (Schema::hasTable('pending_staff_accounts')) {
            $reserved = PendingStaffAccount::query()->whereRaw('LOWER(username) = ?', [$normalized]);
            if ($staffEmail !== null) $reserved->whereRaw('LOWER(email) <> ?', [Str::lower($staffEmail)]);
            if ($lockForUpdate) $reserved->lockForUpdate();
            if ($reserved->exists()) return false;
        }

        return true;
    }

    public static function isAvailableToStaff(string $username, User $staff, bool $lockForUpdate = false): bool
    {
        return self::isAvailable($username, $staff->getKey(), $lockForUpdate, $staff->email);
    }
}
