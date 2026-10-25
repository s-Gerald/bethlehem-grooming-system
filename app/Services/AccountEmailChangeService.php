<?php

namespace App\Services;

use App\Models\LoginEmailChallenge;
use App\Models\PasswordResetRequest;
use App\Models\PrivilegedCredentialChange;
use App\Models\StaffEmailChange;
use App\Models\User;
use App\Support\AccountEmail;

class AccountEmailChangeService
{
    // Call only with the account row locked in the caller's transaction.
    public function complete(User $user, string $email): void
    {
        AccountEmail::assertAvailable($email, $user, true);
        $user->email = $email;
        $user->email_verified_at = now();
        $user->save();
        $this->revoke($user);
    }

    public function revoke(User $user): void
    {
        LoginEmailChallenge::where('user_id', $user->getKey())->delete();
        PasswordResetRequest::where('user_id', $user->getKey())->delete();
        PrivilegedCredentialChange::where('target_user_id', $user->getKey())->whereNull('confirmed_at')->delete();
        StaffEmailChange::where('user_id', $user->getKey())->delete();
        $user->tokens()->delete();
    }
}
