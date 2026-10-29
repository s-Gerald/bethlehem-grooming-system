<?php

namespace App\Services;

use App\Http\Middleware\UseAccountSessionCookie;
use App\Models\RememberedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

class RememberedDeviceService
{
    public const COOKIE = 'bethlehem_remembered_device';
    public const CONCURRENT_RETRY_SECONDS = 60;

    public function revokeFor(User $user, ?int $except = null): void
    {
        // Some existing installation/test schemas predate device credentials.
        if (! Schema::hasTable('remembered_devices')) return;
        RememberedDevice::where('user_id', $user->getKey())->whereNull('revoked_at')
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))->update(['revoked_at' => now()]);
    }

    public function issue(User $user, string $sessionToken): Cookie
    {
        $device = RememberedDevice::create(['user_id' => $user->getKey(),
            'session_token_id' => (int) explode('|', $sessionToken)[0],
            'expires_at' => now()->addDays(14), 'last_used_at' => now()]);
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $this->insertCredential($device, $selector, $validator);
        return $this->cookie($device, $selector, $validator);
    }

    public function recordActivity(User $user, PersonalAccessToken $token): void
    {
        if (! in_array('session:persistent', $token->abilities ?? [], true)) return;
        // At most one device write per hour, only on the existing explicit activity endpoint.
        RememberedDevice::where('user_id', $user->getKey())->where('session_token_id', $token->getKey())
            ->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where('last_used_at', '>', now()->subDays(7))->where('last_used_at', '<=', now()->subHour())
            ->update(['last_used_at' => now(), 'updated_at' => now()]);
    }

    public function checkpointSessionActivity(PersonalAccessToken $token): void
    {
        if ($token->name !== 'auth_token' || ! in_array('session:persistent', $token->abilities ?? [], true)
            || ! $token->last_interaction_at || ! Schema::hasTable('remembered_devices')) return;
        RememberedDevice::where('session_token_id', $token->getKey())->whereNull('revoked_at')
            ->where('expires_at', '>', now())->where('last_used_at', '<', $token->last_interaction_at)
            ->update(['last_used_at' => $token->last_interaction_at, 'updated_at' => now()]);
    }

    public function checkpointRetainedSessionActivity(): void
    {
        if (! Schema::hasTable('remembered_devices')) return;
        // One indexed bulk update, including manual Sanctum pruning. Never use
        // the cleanup time as activity: only a real, previously accepted interaction.
        RememberedDevice::whereNull('revoked_at')->where('expires_at', '>', now())
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('personal_access_tokens')
                ->whereColumn('personal_access_tokens.id', 'remembered_devices.session_token_id')
                ->whereColumn('personal_access_tokens.last_interaction_at', '>', 'remembered_devices.last_used_at'))
            ->update(['last_used_at' => DB::raw('(SELECT last_interaction_at FROM personal_access_tokens WHERE personal_access_tokens.id = remembered_devices.session_token_id)'),
                'updated_at' => now()]);
    }

    public function credential(Request $request): ?array
    {
        $value = $request->cookie(self::COOKIE);
        if (! is_string($value) || ! preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/', $value, $parts)) return null;
        return [$parts[1], $parts[2]];
    }

    public function logout(Request $request)
    {
        $raw = $request->bearerToken() ?: $request->cookie(UseAccountSessionCookie::NAME);
        $token = is_string($raw) ? PersonalAccessToken::findToken($raw) : null;
        $parts = $this->credential($request);
        $credential = $parts && Schema::hasTable('remembered_device_credentials')
            ? DB::table('remembered_device_credentials')->where('selector', $parts[0])->first() : null;
        $device = $credential && hash_equals($credential->validator_hash, hash('sha256', $parts[1]))
            ? RememberedDevice::find($credential->remembered_device_id) : null;
        $expectedId = $request->attributes->get('public_session_id');
        $sameDeviceSession = $device && $expectedId && DB::table('remembered_device_credentials')
            ->where('remembered_device_id', $device->getKey())->where('session_token_id', $expectedId)->exists();
        if ($expectedId && ! $token && ! $sameDeviceSession) {
            return response()->json(['success' => false, 'code' => 'session_changed', 'message' => 'Session changed. Please sign in again.'], 401);
        }
        $userId = $token?->tokenable_id ?? $device?->user_id;
        return DB::transaction(function () use ($request, $token, $device, $userId) {
            if ($userId) User::whereKey($userId)->lockForUpdate()->first();
            $token = $token ? PersonalAccessToken::whereKey($token->getKey())->lockForUpdate()->first() : null;
            if ($token?->replaced_at) {
                return response()->json(['success' => false, 'code' => 'session_replaced',
                    'message' => 'Your account was signed in on another browser or device, so this session ended.'], 401);
            }
            if ($token && Schema::hasTable('remembered_devices')) {
                RememberedDevice::where('user_id', $userId)->where('session_token_id', $token->getKey())
                    ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            if ($device && (! $token || ((int) $device->user_id === (int) $userId && (int) $device->session_token_id === $token->getKey()))) {
                $device = RememberedDevice::whereKey($device->getKey())->lockForUpdate()->first();
                $device?->update(['revoked_at' => now()]);
                if (! $token && $device) PersonalAccessToken::whereKey($device->session_token_id)->delete();
            }
            $token?->delete();
            return response()->json(['success' => true, 'message' => 'Logged out successfully'])
                ->withCookie(UseAccountSessionCookie::cookie($request, UseAccountSessionCookie::NAME, '', now()->subYear()))
                ->withCookie(UseAccountSessionCookie::cookie($request, self::COOKIE, '', now()->subYear()))
                ->header('Cache-Control', 'no-store');
        }, 3);
    }

    public function restore(Request $request)
    {
        $attemptAt = now();
        if ($active = $this->recoverActiveSession($request)) return $active;
        $parts = $this->credential($request);
        $credential = $parts ? DB::table('remembered_device_credentials')->where('selector', $parts[0])->first() : null;
        $userId = $credential ? RememberedDevice::whereKey($credential->remembered_device_id)->value('user_id') : null;
        if (! $userId) return $this->rejected();

        return DB::transaction(function () use ($request, $parts, $credential, $userId, $attemptAt) {
            // Same lock order as OTP, session replacement, logout and account security changes.
            $user = User::whereKey($userId)->lockForUpdate()->first();
            $device = RememberedDevice::whereKey($credential->remembered_device_id)->lockForUpdate()->first();
            $credential = DB::table('remembered_device_credentials')->where('selector', $parts[0])->lockForUpdate()->first();
            if (! $user || ! $device || ! $credential || ! hash_equals($credential->validator_hash, hash('sha256', $parts[1]))) {
                return $this->rejected();
            }
            $policy = app(AccountSessionService::class);
            $token = $user->tokens()->whereKey($device->session_token_id)->first();
            $lastActivity = $token?->last_interaction_at
                ? $device->last_used_at->copy()->max(Carbon::parse($token->last_interaction_at)) : $device->last_used_at;
            if ($device->revoked_at) return $this->rejected();
            if ($user->role !== 'customer' || ! $policy->accountIsUsable($user)
                || now()->gte($device->expires_at) || now()->gte($lastActivity->copy()->addDays(7))) {
                $device->update(['revoked_at' => now()]);
                return $this->rejected();
            }
            // Account switching must never adopt another account's ambient device cookie.
            if ($request->input('account_id') && (string) $request->input('account_id') !== (string) $user->getKey()) return $this->rejected();

            if (! $credential->consumed_at && $token && $policy->isUsable($token, $user)) {
                if (in_array('session:remembered', $token->abilities ?? [], true)) {
                    return $this->authenticated($user, $device, $token, $this->sessionSecret($token, $parts[0], $parts[1]));
                }
            }

            [$selector, $validator] = $this->successor($parts[0], $parts[1]);
            if ($credential->consumed_at) {
                $successor = DB::table('remembered_device_credentials')->where('selector', $selector)->first();
                // Identical overlapping requests receive identical successors and sessions.
                if ($attemptAt->lt(Carbon::parse($credential->consumed_at)->addSeconds(self::CONCURRENT_RETRY_SECONDS))
                    && $successor && ! $successor->consumed_at && $token && $policy->isUsable($token, $user)
                    && hash_equals($successor->validator_hash, hash('sha256', $validator))) {
                    return $this->authenticated($user, $device, $token, $this->sessionSecret($token, $selector, $validator), [$selector, $validator]);
                }
                $device->update(['revoked_at' => now()]);
                $token?->delete();
                Log::warning('Remembered device replay revoked.', ['user_id' => $user->getKey(), 'device_id' => $device->getKey()]);
                return $this->rejected();
            }

            DB::table('remembered_device_credentials')->where('id', $credential->id)->update(['consumed_at' => now()]);
            $plainToken = $policy->issue($user, true, $device->getKey());
            $token = PersonalAccessToken::findToken($plainToken);
            $plainToken = $this->sessionSecret($token, $selector, $validator);
            $token->forceFill(['token' => hash('sha256', explode('|', $plainToken, 2)[1]),
                'abilities' => [...$token->abilities, 'session:remembered']])->save();
            $device->update(['session_token_id' => $token->getKey(), 'last_used_at' => now()]);
            $this->insertCredential($device, $selector, $validator);
            Log::info('Customer session restored.', ['user_id' => $user->getKey(), 'device_id' => $device->getKey()]);
            return $this->authenticated($user, $device, $token, $plainToken, [$selector, $validator]);
        }, 3);
    }

    private function successor(string $selector, string $validator): array
    {
        // Keyed derivation permits safe idempotent retries without storing any recoverable secret.
        return [substr(hash_hmac('sha256', 'selector:'.$selector.':'.$validator, config('app.key')), 0, 32),
            hash_hmac('sha256', 'validator:'.$selector.':'.$validator, config('app.key'))];
    }

    private function recoverActiveSession(Request $request)
    {
        $raw = $request->cookie(UseAccountSessionCookie::NAME);
        $token = is_string($raw) ? PersonalAccessToken::findToken($raw) : null;
        if (! $token || ! $token->tokenable instanceof User || $token->tokenable->role !== 'customer') return null;
        return DB::transaction(function () use ($request, $token, $raw) {
            $user = User::whereKey($token->tokenable_id)->lockForUpdate()->first();
            $session = PersonalAccessToken::whereKey($token->getKey())->lockForUpdate()->first();
            if (! $user || ! $session || ! app(AccountSessionService::class)->isUsable($session, $user)
                || ($request->input('account_id') && (string) $request->input('account_id') !== (string) $user->getKey())) return null;
            // Recover the public reference in another tab; do not create, unlock or extend a session.
            return $this->authenticated($user, null, $session, $raw);
        }, 3);
    }

    private function sessionSecret(PersonalAccessToken $token, string $selector, string $validator): string
    {
        return $token->getKey().'|'.hash_hmac('sha256', 'session:'.$token->getKey().':'.$selector.':'.$validator, config('app.key'));
    }

    private function insertCredential(RememberedDevice $device, string $selector, string $validator): void
    {
        DB::table('remembered_device_credentials')->insert(['remembered_device_id' => $device->getKey(),
            'selector' => $selector, 'validator_hash' => hash('sha256', $validator),
            'session_token_id' => $device->session_token_id, 'created_at' => now()]);
    }

    private function cookie(RememberedDevice $device, string $selector, string $validator): Cookie
    {
        return UseAccountSessionCookie::cookie(request(), self::COOKIE, $selector.'.'.$validator, $device->expires_at);
    }

    private function authenticated(User $user, ?RememberedDevice $device, PersonalAccessToken $session, string $token, ?array $rotation = null)
    {
        $policy = app(AccountSessionService::class);
        $response = response()->json(['success' => true, 'token' => $token,
            'remember_me' => in_array('session:persistent', $session->abilities ?? [], true),
            'locked' => false, 'server_now' => now()->toIso8601String(),
            'idle_at' => $policy->idleAt($session)->toIso8601String(),
            'expires_at' => $policy->expiresAt($session, $user)->toIso8601String(),
            'user' => ['user_id' => $user->getKey(), 'role' => 'customer', 'first_name' => $user->first_name, 'last_name' => $user->last_name]])
            ->header('Cache-Control', 'no-store');
        return $rotation ? $response->withCookie($this->cookie($device, ...$rotation)) : $response;
    }

    private function rejected()
    {
        // Do not expire cookies here: a delayed rejection must not erase a newer login's cookies.
        return response()->json(['success' => false, 'code' => 'remembered_session_invalid',
            'message' => 'Session expired. Please sign in again.'], 401)->header('Cache-Control', 'no-store');
    }
}
