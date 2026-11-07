<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\AccountSessionService;
use App\Services\RememberedDeviceService;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class UseAccountSessionCookie
{
    public const NAME = 'bethlehem_account_session';
    public const SELECTION = 'bethlehem_session_selection';

    public static function isAuthenticationRequest(Request $request): bool
    {
        return $request->is('api/register*', 'api/sign-in', 'api/email/*', 'api/password/*', 'api/session*', 'api/logout',
            'api/settings/security/*', 'api/admin/security/*', 'api/staff/password-setup/*', 'api/staff/email-recovery/*',
            'api/customer/password', 'api/customer/security-changes/*');
    }

    public static function isPrivateRequest(Request $request): bool
    {
        return self::isAuthenticationRequest($request)
            || $request->hasHeader('Authorization')
            || ($request->is('api/*', 'admin/activity-log', 'admin/availability') && $request->hasCookie(self::NAME));
    }

    public static function cookie(Request $request, string $name, string $value, $expires = 0): Cookie
    {
        $prefix = rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');
        return Cookie::create($name, $value)->withPath($prefix.($name === self::NAME ? '/' : '/api'))->withHttpOnly(true)->withSameSite('lax')
            ->withSecure(app()->isProduction() || (bool) config('session.secure') || $request->isSecure()
                || str_starts_with(config('app.url'), 'https://'))->withExpires($expires);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $cookie = $request->cookie(self::NAME);
        $page = match ($request->path()) {
            'admin/activity-log', 'api/admin/activity-log/page' => 'activity-log',
            'admin/availability', 'api/admin/availability/page' => 'availability',
            default => null,
        };
        // Scoped transport prevents out-of-order Set-Cookie responses from
        // overwriting a later login. The public selector grants no authority.
        $scoped = $request->header('X-Session-Transport') === 'scoped'
            || $request->hasCookie(self::SELECTION);
        $sessionId = null;
        $restorePredecessor = null;
        if ($scoped) {
            $reference = $bearer ?: $request->header('X-Session-Reference', $request->cookie(self::SELECTION, ''));
            if (preg_match('/^(\d+)(?:\|.*)?$/D', (string) $reference, $selected)) $sessionId = $selected[1];
            if ($request->routeIs('session.restore')) $restorePredecessor = $sessionId;
            $cookie = $sessionId ? $request->cookie(self::NAME.'_'.$sessionId) : null;
            $request->cookies->set(self::NAME, $cookie);
            $request->cookies->set(RememberedDeviceService::COOKIE,
                $sessionId ? $request->cookie(RememberedDeviceService::COOKIE.'_'.$sessionId) : null);
        }
        $canonicalPage = $page && $request->is('admin/*');
        // Existing deployments issued /api-scoped cookies. Let the authorized API
        // page upgrade that same credential once, without a new login or token.
        if ($canonicalPage && ! $bearer && ! $cookie) {
            return redirect('/api/admin/'.$page.'/page?'.http_build_query([...$request->query(), 'canonical' => '1']))
                ->header('Cache-Control', 'no-store, private');
        }
        // Protected HTML uses normal navigation without a JavaScript bearer header.
        if ($page && ! $bearer && is_string($cookie)) {
            $request->headers->set('Authorization', 'Bearer '.$cookie);
        }
        $cookieRequest = is_string($bearer) && preg_match('/^(\d+)\|cookie$/D', $bearer, $match);
        if ($scoped && $request->routeIs('auth.logout') && ! $bearer) {
            // A queued sign-out without an original reference must never act on
            // whatever account the browser happens to select when it arrives.
            return response()->json(['success' => false, 'code' => 'session_changed',
                'message' => 'Session changed. Please sign in again.'], 401)->header('Cache-Control', 'no-store');
        }
        // Custom headers and an explicit origin allowlist prevent cookie/login
        // CSRF. Raw bearer clients do not rely on ambient credentials.
        $browserMutation = ! $request->isMethodSafe() && ! $bearer && ! $request->is('api/paymongo/webhook');
        $ambientControl = $request->routeIs('session.restore', 'auth.logout');
        if ($cookieRequest || $browserMutation || $ambientControl) {
            $origin = $request->headers->get('Origin');
            if ($request->header('X-Requested-With') !== 'XMLHttpRequest'
                || $request->header('Sec-Fetch-Site') === 'cross-site'
                || ($origin && ! in_array($origin, config('cors.allowed_origins'), true))) {
                return response()->json(['message' => 'Request origin could not be verified.'], 419)->header('Cache-Control', 'no-store');
            }
        }
        if ($cookieRequest) {
            $request->attributes->set('public_session_id', (int) $match[1]);
            if (! is_string($cookie) || ! str_starts_with($cookie, $match[1].'|')) {
                // Logout can revoke a device after the shorter active cookie has expired.
                if (! $request->routeIs('auth.logout')) {
                    return response()->json(['success' => false, 'code' => 'session_changed', 'message' => 'Please sign in again.'], 401)->header('Cache-Control', 'no-store');
                }
            } else {
                $request->headers->set('Authorization', 'Bearer '.$cookie);
            }
        }

        $response = $next($request);
        if ($page && ! $canonicalPage && $request->query('canonical') === '1' && $response->isSuccessful()) {
            $query = $request->query();
            unset($query['canonical']);
            $token = $request->user()->currentAccessToken();
            $persistent = in_array('session:persistent', $token->abilities ?? [], true);
            $expiry = $persistent ? app(AccountSessionService::class)->expiresAt($token, $request->user()) : 0;
            $response = redirect('/admin/'.$page.($query ? '?'.http_build_query($query) : ''))
                ->withCookie(self::cookie($request, self::NAME, $request->bearerToken(), $expiry));
        }
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);
            $issuedToken = $data['token'] ?? null;
            // Upgrade existing clients on the read-only status endpoint without
            // extending deadlines or creating/replacing any authenticated token.
            if ($request->routeIs('session.status') && ! $cookieRequest && $request->bearerToken()) $issuedToken = $request->bearerToken();
            if (is_string($issuedToken) && preg_match('/^(\d+)\|(.+)$/D', $issuedToken, $issued)
                && (isset($data['user']['role']) || $request->routeIs('session.status'))) {
                $token = $request->routeIs('session.status') ? $request->user()->currentAccessToken() : PersonalAccessToken::findToken($issuedToken);
                $abilities = $token?->abilities ?? [];
                $legacy = ! in_array('session:persistent', $abilities, true) && ! in_array('session:temporary', $abilities, true);
                $persistent = in_array('session:persistent', $abilities, true)
                    || ($legacy && $request->routeIs('session.status') && $request->header('X-Session-Persistent') === '1');
                $expiry = $token ? app(AccountSessionService::class)->expiresAt($token, $request->routeIs('session.status') ? $request->user() : $token->tokenable) : 0;
                if ($issuedToken !== $cookie) {
                    $response->headers->setCookie(self::cookie($request, self::NAME, $issuedToken, $persistent ? $expiry : 0));
                }
                if ($scoped) {
                    $sessionId = $issued[1];
                    if (isset($data['token'])) $data['session_transport'] = 'scoped';
                }
                if (isset($data['token'])) $data['token'] = $issued[1].'|cookie';
                $response->setData($data)->header('Cache-Control', 'no-store');
            }
        }

        if ($scoped) {
            foreach ($response->headers->getCookies() as $responseCookie) {
                if (! in_array($responseCookie->getName(), [self::NAME, RememberedDeviceService::COOKIE], true)) continue;
                $response->headers->removeCookie($responseCookie->getName(), $responseCookie->getPath(), $responseCookie->getDomain());
                if ($sessionId) {
                    $response->headers->setCookie(new Cookie($responseCookie->getName().'_'.$sessionId,
                        $responseCookie->getValue(), $responseCookie->getExpiresTime(), $responseCookie->getPath(),
                        $responseCookie->getDomain(), $responseCookie->isSecure(), true, false, 'lax'));
                }
            }
            // Delete only older names actually sent with this issuance. An
            // overlapping newer response owns a higher ID and is never cleared.
            if (isset($issued) && $sessionId) {
                foreach ($request->cookies->keys() as $name) {
                    // A response can deliver its cookies before its body. Keep
                    // the immediate predecessor for the existing bounded retry.
                    if ($restorePredecessor && $name === RememberedDeviceService::COOKIE.'_'.$restorePredecessor) continue;
                    if (preg_match('/^(bethlehem_account_session|bethlehem_remembered_device)_(\d+)$/D', $name, $old)
                        && (int) $old[2] < (int) $sessionId) {
                        $expired = self::cookie($request, $old[1], '', now()->subYear());
                        $response->headers->setCookie(new Cookie($name, '', $expired->getExpiresTime(),
                            $expired->getPath(), null, $expired->isSecure(), true, false, 'lax'));
                    }
                }
            }
        }

        // Remove the old path on every legacy issuance, upgrade and expiration (logout).
        // Remembered-device cookies retain their established /api scope.
        foreach ($response->headers->getCookies() as $responseCookie) {
            if ($responseCookie->getName() === self::NAME) {
                $prefix = rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');
                $response->headers->setCookie(self::cookie($request, self::NAME, '', now()->subYear())->withPath($prefix.'/api'));
                break;
            }
        }

        if ($page || self::isPrivateRequest($request)) {
            $response->headers->set('Cache-Control', 'no-store');
        }
        return $response;
    }
}
