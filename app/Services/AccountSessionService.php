<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountSessionService
{
    public function isUsable(PersonalAccessToken $token, User $user): bool
    {
        return $token->tokenable_type === $user->getMorphClass() && (int) $token->tokenable_id === (int) $user->getKey()
            && ! $token->replaced_at && ! $token->locked_at && ! $user->hasEmailRecoveryHold()
            && (! $token->expires_at || now()->lt($token->expires_at))
            && now()->lt($token->created_at->copy()->addHours(12))
            && now()->lt(Carbon::parse($token->last_interaction_at ?? $token->created_at)->addMinutes(30));
    }

    // Only call after all authentication requirements have succeeded.
    public function issue(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($account->is_active && ! $account->is_archived && ! $account->hasEmailRecoveryHold(), 403, 'This account is unavailable.');

            $account->tokens()->where('created_at', '<=', now()->subDay())->delete();
            $replaced = $account->tokens()->whereNull('replaced_at')->update(['replaced_at' => now()]);
            $token = $account->createToken(in_array($account->role, ['admin', 'staff'], true) ? 'admin_token' : 'auth_token');

            if ($replaced) {
                Log::info('Account session replaced.', ['user_id' => $account->getKey(), 'sessions_replaced' => $replaced]);
            }

            if (in_array($account->role, ['admin', 'staff'], true)) {
                app(ActivityRecorder::class)->record('sign_in', 'system', 'account', (string) $account->getKey(),
                    'Staff sign-in', [], false, $account, 'sign-in:'.$token->accessToken->getKey());
            }
            return $token->plainTextToken;
        });
    }
}
