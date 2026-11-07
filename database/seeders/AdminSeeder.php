<?php

namespace Database\Seeders;

use App\Models\User;
use App\Rules\AccountPassword;
use App\Support\AccountUsername;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $credentials = config('app.privileged_seed_accounts.admin');

        if (! $this->credentialsArePresent($credentials)) {
            $this->command?->warn('Admin user not seeded: set ADMIN_SEED_USERNAME, ADMIN_SEED_EMAIL, ADMIN_SEED_PHONE, and ADMIN_SEED_PASSWORD.');
            return;
        }

        $this->validateCredentials($credentials);
        $email = Str::lower(trim($credentials['email']));
        $username = trim($credentials['username']);
        $emailOwner = User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();
        $usernameOwner = User::whereRaw('LOWER(username) = ?', [Str::lower($username)])->first();

        if ($emailOwner && $emailOwner->role !== 'admin') {
            throw new InvalidArgumentException('ADMIN_SEED_EMAIL already belongs to a non-admin account.');
        }

        if ($usernameOwner && $usernameOwner->role !== 'admin') {
            throw new InvalidArgumentException('ADMIN_SEED_USERNAME already belongs to a non-admin account.');
        }

        if ($emailOwner && $usernameOwner && ! $emailOwner->is($usernameOwner)) {
            throw new InvalidArgumentException('ADMIN_SEED_EMAIL and ADMIN_SEED_USERNAME belong to different admin accounts.');
        }

        $admin = $emailOwner ?? $usernameOwner ?? new User();
        Validator::make(['username' => $username], ['username' => AccountUsername::rules(unchanged: $admin->username)])->validate();
        if ($username !== $admin->username && ! AccountUsername::isAvailable($username, $admin->getKey() ?? 0)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['username' => 'That username is already in use.']);
        }
        $changesPassword = ! $admin->exists || ! Hash::check($credentials['password'], $admin->password_hash);
        if ($changesPassword) {
            Validator::make(['password' => $credentials['password']], [
                'password' => AccountPassword::rules('admin', ['username' => $username, 'email' => $email]),
            ])->validate();
        }
        $admin->fill([
            'username' => $username,
            'email' => $email,
            'first_name' => 'Admin',
            'last_name' => 'Bethlehem',
            'phone' => $credentials['phone'],
            'password_hash' => $changesPassword ? Hash::make($credentials['password']) : $admin->password_hash,
            'role' => 'admin',
            'customer_tier' => 'new',
            'is_active' => 1,
            'is_archived' => 0,
            'email_verified_at' => now(),
        ])->save();
    }

    private function credentialsArePresent(array $credentials): bool
    {
        return filled($credentials['username'])
            && filled($credentials['email'])
            && filled($credentials['phone'])
            && filled($credentials['password']);
    }

    private function validateCredentials(array $credentials): void
    {
        if (! filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('ADMIN_SEED_EMAIL must be a valid email address.');
        }

        if (! preg_match('/^09\d{9}$/', $credentials['phone'])) {
            throw new InvalidArgumentException('ADMIN_SEED_PHONE must be an 11-digit Philippine mobile number.');
        }
    }
}
