<?php

namespace App\Support;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountEmail
{
    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    public static function rules(): array
    {
        return ['required', 'string', 'email:rfc',
            'regex:/^[^\s@]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/', 'max:150'];
    }

    public static function assertAvailable(string $email, ?User $except = null, bool $lock = false): void
    {
        $column = DB::getDriverName() === 'sqlite' ? 'email COLLATE NOCASE' : 'email';
        $pending = PendingCustomerRegistration::query()->unexpired()->whereRaw($column.' = ?', [$email]);
        $users = User::query()->whereRaw($column.' = ?', [$email])
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()));
        if ($lock) {
            $pending->lockForUpdate();
            $users->lockForUpdate();
        }
        if ($pending->first(['id']) || $users->first(['user_id'])) {
            throw ValidationException::withMessages(['email' => 'That email address is already in use.']);
        }
    }
}
