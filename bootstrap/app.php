<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureClinicPermission;
use App\Http\Middleware\EnsureGroomingPermission;
use App\Http\Middleware\EnsureAccountIsUsable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT);
        // Protected HTML routes use the same sign-in destination as the frontend.
        // API authentication errors still render the JSON 401 response below.
        $middleware->redirectGuestsTo('/sign-in');
        // Validate grooming price input as entered, including malformed whitespace.
        $middleware->trimStrings(except: [
            fn ($request) => $request->is('api/admin/grooming/services/*/pricing'),
        ]);
        $middleware->alias([
            'account.usable' => EnsureAccountIsUsable::class,
            'session.active' => \App\Http\Middleware\EnsureSessionIsActive::class,
            'role' => EnsureUserHasRole::class,
            'clinic' => EnsureClinicPermission::class,
            'grooming' => EnsureGroomingPermission::class,
            'staff' => \App\Http\Middleware\EnsureStaffPermission::class,
        ]);

        $middleware->api(prepend: [
            HandleCors::class,
            \App\Http\Middleware\UseAccountSessionCookie::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (\Throwable $exception) {
            if (request()->is('api/customer/password', 'api/customer/security-changes/*')) {
                // Exception messages/traces can contain SQL bindings or HTTP
                // payloads. Keep this credential endpoint's failure log allowlisted.
                \Illuminate\Support\Facades\Log::error(request()->is('api/customer/password', 'api/customer/security-changes/password/*')
                    ? 'Customer password change failed.' : 'Customer email change failed.', [
                    'exception_type' => $exception::class, 'user_id' => request()->user()?->getKey(),
                ]);
                return false;
            }
        });
        $exceptions->respond(function ($response) {
            if (request()->is('api/register', 'api/password/forgot') && $response instanceof \Illuminate\Http\JsonResponse
                && ($response->getData(true)['code'] ?? null) === 'email_delivery_failed') {
                return $response;
            }
            if ($response instanceof \Illuminate\Http\JsonResponse
                && ($response->getData(true)['code'] ?? null) === 'password_screening_unavailable') {
                return $response;
            }
            if (\App\Http\Middleware\UseAccountSessionCookie::isPrivateRequest(request())) {
                $response->headers->set('Cache-Control', 'no-store');
            }
            if (request()->is('api/register*', 'api/sign-in', 'api/email/*', 'api/password/*', 'api/customer/password', 'api/customer/security-changes/*')
                && $response->getStatusCode() >= 500) {
                return response()->json(['success' => false, 'message' => request()->is('api/register*')
                    ? "Couldn't create your account. Please try again."
                    : 'Something went wrong. Please try again.'], $response->getStatusCode())
                    ->header('Cache-Control', 'no-store');
            }
            return $response;
        });
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/admin/activity-log/page', 'api/admin/availability/page') && $request->query('canonical') === '1') {
                return redirect('/sign-in')->header('Cache-Control', 'no-store, private');
            }
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.',
                ], 401);
            }
        });
    })->create();
