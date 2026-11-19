<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationRouteThrottleTest extends TestCase
{
    public function test_authentication_routes_use_short_flood_windows_and_keep_business_limits(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($route) => implode('|', $route->methods()).' '.$route->uri());
        foreach ([
            'staff/password-setup/request-new-link' => 'throttle:30,1',
            'settings/security/password-change' => 'throttle:10,1',
            'settings/security/email-change' => 'throttle:10,1,staff-email-start',
            'settings/security/email-change/{change}/resend' => 'throttle:30,1,staff-email-resend',
            'settings/security/credential-changes/{change}/resend' => 'App\Http\Middleware\ThrottleAuthenticationResends:30,1',
            'admin/security/account/credential-change' => 'throttle:10,1',
            'admin/security/staff/{staff}/setup-email' => 'throttle:30,1',
            'admin/security/staff/{staff}/email-recovery' => 'throttle:10,1,admin-recovery-start',
            'admin/security/staff/{staff}/email-recovery/{recovery}/code' => 'throttle:30,1,admin-recovery-code',
            'admin/security/staff/{staff}/email-recovery/{recovery}/resend' => 'throttle:30,1,admin-recovery-resend',
            'admin/security/credential-changes/{change}/resend' => 'App\Http\Middleware\ThrottleAuthenticationResends:30,1',
            'admin/security/accounts' => 'throttle:3,10',
            'admin/security/staff-accounts' => 'throttle:3,10',
        ] as $uri => $middleware) {
            $route = $routes->get('POST api/'.$uri);
            $this->assertNotNull($route, $uri);
            $this->assertContains($middleware, $route->gatherMiddleware(), $uri);
        }
    }
}
