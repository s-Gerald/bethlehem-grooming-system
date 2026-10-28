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
        // Protected HTML routes use the same sign-in destination as the frontend.
        // API authentication errors still render the JSON 401 response below.
        $middleware->redirectGuestsTo('/pages/client/sign-in.html');
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
        $exceptions->respond(function ($response) {
            if (request()->is('api/register*', 'api/sign-in', 'api/email/*', 'api/password/*') && $response->getStatusCode() >= 500) {
                return response()->json(['success' => false, 'message' => request()->is('api/register*')
                    ? "Couldn't create your account. Please try again."
                    : 'Something went wrong. Please try again.'], $response->getStatusCode())
                    ->header('Cache-Control', 'no-store');
            }
            return $response;
        });
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.',
                ], 401);
            }
        });
    })->create();
