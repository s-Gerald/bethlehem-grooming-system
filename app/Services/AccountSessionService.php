<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountSessionService
{
    // Only call after all authentication requirements have succeeded.
    public function issue(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($account->is_active && ! $account->is_archived, 403, 'This account is unavailable.');

            $account->tokens()->where('created_at', '<=', now()->subDay())->delete();
            $replaced = $account->tokens()->whereNull('replaced_at')->update(['replaced_at' => now()]);
            $token = $account->createToken(in_array($account->role, ['admin', 'staff'], true) ? 'admin_token' : 'auth_token');

            if ($replaced) {
                Log::info('Account session replaced.', ['user_id' => $account->getKey(), 'sessions_replaced' => $replaced]);
            }

            return $token->plainTextToken;
        });
    }
}
