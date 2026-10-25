<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

class EnsureBookingForecastSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // General availability is public; an owned booking forecast is not.
        if (! $request->filled('booking_id')) {
            return $next($request);
        }

        return app(Pipeline::class)->send($request)->through([
            Authenticate::using('sanctum'),
            EnsureAccountIsUsable::class,
            EnsureSessionIsActive::class,
        ])->then($next);
    }
}
