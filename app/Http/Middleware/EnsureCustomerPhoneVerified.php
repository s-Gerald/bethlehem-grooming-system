<?php

namespace App\Http\Middleware;

use App\Services\CustomerPhoneVerificationService;
use Closure;
use Illuminate\Http\Request;

class EnsureCustomerPhoneVerified
{
    public function handle(Request $request, Closure $next)
    {
        CustomerPhoneVerificationService::assertPreRegistration($request->user());

        return $next($request);
    }
}
