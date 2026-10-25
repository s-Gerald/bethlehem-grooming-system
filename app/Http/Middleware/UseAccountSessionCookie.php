<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class UseAccountSessionCookie
{
    public const NAME = 'bethlehem_account_session';

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $cookie = $request->cookie(self::NAME);
        $cookieRequest = is_string($bearer) && preg_match('/^(\d+)\|cookie$/D', $bearer, $match);
        // Custom headers and an explicit origin allowlist prevent cookie/login
        // CSRF. Raw bearer clients do not rely on ambient credentials.
        $browserMutation = ! $request->isMethodSafe() && ! $bearer && ! $request->is('api/paymongo/webhook');
        if ($cookieRequest || $browserMutation) {
            $origin = $request->headers->get('Origin');
            if ($request->header('X-Requested-With') !== 'XMLHttpRequest'
                || ($origin && ! in_array($origin, config('cors.allowed_origins'), true))) {
                return response()->json(['message' => 'Request origin could not be verified.'], 419);
            }
        }
        if ($cookieRequest) {
            if (! is_string($cookie) || ! str_starts_with($cookie, $match[1].'|')) {
                return response()->json(['success' => false, 'code' => 'session_changed', 'message' => 'Please sign in again.'], 401);
            }
            $request->headers->set('Authorization', 'Bearer '.$cookie);
        }

        $response = $next($request);
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);
            $issuedToken = $data['token'] ?? null;
            // Upgrade existing clients on the read-only status endpoint without
            // extending deadlines or creating/replacing any authenticated token.
            if ($request->routeIs('session.status') && $bearer && ! $cookieRequest) $issuedToken = $bearer;
            if (is_string($issuedToken) && preg_match('/^(\d+)\|(.+)$/D', $issuedToken, $issued)
                && (isset($data['user']['role']) || $request->routeIs('session.status'))) {
                $role = $data['user']['role'] ?? $request->user()->role;
                $privileged = in_array($role, ['admin', 'staff'], true);
                $persistent = $privileged || ($request->routeIs('session.status')
                    ? $request->header('X-Session-Persistent') === '1'
                    : ($data['remember_me'] ?? true));
                $expiry = $request->routeIs('session.status')
                    ? $request->user()->currentAccessToken()->created_at->copy()->addHours($privileged ? 12 : 24)
                    : now()->addHours($privileged ? 12 : 24);
                $response->headers->setCookie(Cookie::create(self::NAME, $issuedToken)
                    ->withPath('/api')->withHttpOnly(true)->withSameSite('lax')
                    ->withSecure((bool) config('session.secure') || $request->isSecure() || str_starts_with(config('app.url'), 'https://'))
                    ->withExpires($persistent ? $expiry : 0));
                if (isset($data['token'])) $data['token'] = $issued[1].'|cookie';
                $response->setData($data)->header('Cache-Control', 'no-store');
            }
        }

        return $response;
    }
}
