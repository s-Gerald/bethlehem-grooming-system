<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use App\Services\AccountSessionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->user()->currentAccessToken();
        // These APIs use bearer authentication. Cookie-only authentication must
        // not become a way around the token's inactivity/absolute limits.
        if ($accessToken instanceof TransientToken) {
            return response()->json(['message' => 'Please sign in again.'], 401);
        }
        // Sanctum::actingAs supplies a non-persisted test token.
        if (! $accessToken instanceof Model || ! $accessToken->exists) {
            return app()->environment('testing')
                ? $next($request)
                : response()->json(['message' => 'Please sign in again.'], 401);
        }

        // Email changes revalidate account/session inside their own retryable
        // transaction. Keep that transaction outside the HTTP exception renderer.
        $ownsTransaction = $request->routeIs('staff.email-change.*', 'admin.staff-recovery.*');
        $lockAccount = (! $request->isMethodSafe() && ! $ownsTransaction)
            || $request->attributes->get('lock_account_for_expiration', false);
        $response = DB::transaction(function () use ($request, $accessToken, $next, $ownsTransaction, $lockAccount) {
            // Keep mutations ordered against takeover through their controller.
            if ($lockAccount) {
                $account = $request->user()->newQuery()->whereKey($request->user()->getKey())
                    ->lockForUpdate()->firstOrFail();
                if (! app(AccountSessionService::class)->accountIsUsable($account)) {
                    return response()->json(['code' => 'account_disabled', 'message' => 'This account is unavailable. Contact the clinic.'], 403);
                }
            }
            $token = $accessToken->newQuery()->lockForUpdate()->find($accessToken->getKey());
            if (! $token) {
                return response()->json(['message' => 'Please sign in again.'], 401);
            }

            $now = now();
            $privileged = in_array($request->user()->role, ['admin', 'staff'], true);
            $policy = app(AccountSessionService::class);
            $expiresAt = $policy->expiresAt($token, $request->user());
            $idleAt = $policy->idleAt($token);
            if ($now->gte($expiresAt) || (! $privileged && $now->gte($idleAt))) {
                if (! $privileged && ! $lockAccount) return false;
                if (! $privileged) app(\App\Services\RememberedDeviceService::class)->checkpointSessionActivity($token);
                $token->delete();

                return response()->json([
                    'success' => false, 'code' => 'session_expired', 'message' => 'Session expired. Please sign in again.',
                ], 401);
            }

            if ($token->getAttribute('replaced_at')) {
                return response()->json([
                    'success' => false, 'code' => 'session_replaced',
                    'message' => 'Your account was signed in on another browser or device, so this session ended.',
                ], 401)->header('Cache-Control', 'no-store');
            }

            if ($privileged && ! $token->locked_at && $now->gte($idleAt)) {
                $token->forceFill(['locked_at' => $now])->save();
            }
            $session = [
                'locked' => (bool) $token->locked_at,
                'server_now' => $now->toIso8601String(),
                'idle_at' => $idleAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ];
            $request->user()->withAccessToken($token);
            $request->attributes->set('auth_session', $session);

            if ($token->locked_at && ! $request->routeIs('session.status', 'session.unlock', 'auth.logout')) {
                return response()->json([
                    'success' => false, 'code' => 'session_locked', 'message' => 'Session locked.',
                    ...$session,
                ], 423);
            }

            // Only this explicit interaction endpoint updates inactivity. The
            // browser supplies no authoritative time and late reports are denied.
            if ($request->routeIs('session.activity')) {
                $token->forceFill(['last_interaction_at' => $now])->save();
                if (! $privileged) app(\App\Services\RememberedDeviceService::class)->recordActivity($request->user(), $token);
                $request->attributes->set('auth_session', [
                    ...$session, 'idle_at' => $now->copy()->addMinutes(30)->toIso8601String(),
                ]);
            }

            return $request->isMethodSafe() || $ownsTransaction ? null : $next($request);
        });

        if ($response === false) {
            // Release the read lock, then reacquire account -> token -> device.
            // Checkpoint and deletion stay atomic without locking every normal GET.
            $request->attributes->set('lock_account_for_expiration', true);
            return $this->handle($request, $next);
        }
        return $response ?? $next($request);
    }
}
