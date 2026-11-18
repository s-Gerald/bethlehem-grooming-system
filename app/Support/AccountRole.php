<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class AccountRole
{
    public static function manageable(): array
    {
        return ['manager', ...array_keys(StaffRole::TYPES)];
    }

    public static function fields(string $role): array
    {
        if (! in_array($role, self::manageable(), true)) {
            throw ValidationException::withMessages(['account_role' => 'Choose a supported account role.']);
        }

        return $role === 'manager'
            ? ['role' => 'admin', 'admin_type' => 'manager', 'staff_type' => null, 'staff_subrole' => null]
            : ['role' => 'staff', 'admin_type' => null, ...StaffRole::fields($role)];
    }
}
