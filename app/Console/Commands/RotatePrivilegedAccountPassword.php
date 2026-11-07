<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Rules\AccountPassword;

class RotatePrivilegedAccountPassword extends Command
{
    protected $signature = 'account:rotate-privileged-password
                            {identifier : Admin or staff email address/username}';

    protected $description = 'Securely rotate an admin or staff password and revoke its active sessions';

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('identifier'));
        $user = User::query()
            ->whereIn('role', ['admin', 'staff'])
            ->where(function ($query) use ($identifier) {
                $query->where('email', $identifier)
                    ->orWhere('username', $identifier);
            })
            ->first();

        if (! $user) {
            $this->error('No admin or staff account matched that identifier.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password');
        $confirmation = (string) $this->secret('Confirm new password');
        if (Hash::check($password, $user->password_hash)) {
            $this->error('Choose a different password.');
            return self::FAILURE;
        }
        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            [
                'password' => AccountPassword::rules($user->role, $user->toArray()),
                'password_confirmation' => ['bail', 'required', 'string', 'same:password'],
            ],
            AccountPassword::messages(),
        );

        try {
            $invalid = $validator->fails();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException) {
            $this->error('Password safety checking is temporarily unavailable. Please try again later.');
            return self::FAILURE;
        }
        if ($invalid) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->update(['password_hash' => Hash::make($password)]);
        $user->tokens()->delete();

        $this->info("Password rotated and active sessions revoked for {$user->email}.");

        return self::SUCCESS;
    }
}
