<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountSessionService
{
    public function expiresAt(PersonalAccessToken $token, User $user): Carbon
    {
        $limit = $token->created_at->copy()->addHours(in_array($user->role, ['admin', 'staff'], true) ? 12 : 24);
        return $token->expires_at && $token->expires_at->lt($limit) ? $token->expires_at->copy() : $limit;
    }

    public function idleAt(PersonalAccessToken $token): Carbon
    {
        return Carbon::parse($token->last_interaction_at ?? $token->created_at)->addMinutes(30);
    }

    public function accountIsUsable(User $user): bool
    {
        return $user->is_active && ! $user->is_archived && ! $user->account_deleted_at
            && ! $user->hasEmailRecoveryHold() && ! $user->requiresPasswordSetup()
            && in_array($user->role, ['customer', 'admin', 'staff'], true)
            && ($user->role !== 'customer' || $user->email_verified_at !== null);
    }

    public function isUsable(PersonalAccessToken $token, User $user): bool
    {
        return $token->tokenable_type === $user->getMorphClass() && (int) $token->tokenable_id === (int) $user->getKey()
            && ! $token->replaced_at && ! $token->locked_at && $this->accountIsUsable($user)
            && now()->lt($this->expiresAt($token, $user)) && now()->lt($this->idleAt($token));
    }

    // Only call after all authentication requirements have succeeded.
    public function issue(User $user, bool $remember = false, ?int $rememberedDeviceId = null): string
    {
        return DB::transaction(function () use ($user, $remember, $rememberedDeviceId) {
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($this->accountIsUsable($account), 403, 'This account is unavailable.');

            app(RememberedDeviceService::class)->revokeFor($account, $rememberedDeviceId);

            $account->tokens()->where('created_at', '<=', now()->subDay())->delete();
            $replaced = $account->tokens()->whereNull('replaced_at')->update(['replaced_at' => now()]);
            $token = $account->createToken(in_array($account->role, ['admin', 'staff'], true) ? 'admin_token' : 'auth_token',
                ['*', $remember ? 'session:persistent' : 'session:temporary']);

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
