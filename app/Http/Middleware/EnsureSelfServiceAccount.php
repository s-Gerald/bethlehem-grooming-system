<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSelfServiceAccount
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->hasSelfServiceSecurity(), 403);

        return $next($request);
    }
}
