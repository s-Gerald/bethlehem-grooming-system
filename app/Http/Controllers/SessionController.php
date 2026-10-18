<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class SessionController extends Controller
{
    public function status(Request $request)
    {
        $user = $request->user();
        $navigation = in_array($user->role, ['admin', 'staff'], true) ? [
            'sidebar_revision' => $user->sidebarRevision(),
            'user_id' => $user->user_id,
            'role' => $user->role,
        ] : [];

        return response()->json(['success' => true, ...$request->attributes->get('auth_session'), ...$navigation])
            ->header('Cache-Control', 'no-store');
    }

    public function unlock(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:100']]);
        $key = 'session-unlock:'.$request->user()->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false, 'message' => 'Too many attempts. Please try again shortly.', 'retry_after' => $seconds,
            ], 429)->header('Retry-After', (string) $seconds);
        }
        if (! Hash::check($data['password'], $request->user()->password_hash)) {
            RateLimiter::hit($key, 60);

            return response()->json(['success' => false, 'message' => 'Incorrect password.'], 422);
        }

        return DB::transaction(function () use ($request, $key) {
            $accessToken = $request->user()->currentAccessToken();
            $token = $accessToken->newQuery()->lockForUpdate()->find($accessToken->getKey());
            if (! $token || now()->gte($token->created_at->copy()->addHours(12))) {
                $token?->delete();

                return response()->json(['message' => 'Session expired. Please sign in again.'], 401);
            }
            $token->forceFill(['locked_at' => null, 'last_interaction_at' => now()])->save();
            RateLimiter::clear($key);
            $request->attributes->set('auth_session', [
                ...$request->attributes->get('auth_session'),
                'locked' => false, 'server_now' => now()->toIso8601String(),
                'idle_at' => now()->addMinutes(30)->toIso8601String(),
            ]);

            return $this->status($request);
        });
    }
}
