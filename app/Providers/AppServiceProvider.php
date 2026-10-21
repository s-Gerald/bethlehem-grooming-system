<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\GroomingTimeEstimate::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sanctum rejects expired tokens before route middleware can revoke them.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(function ($token, bool $valid): bool {
            if (! $valid) {
                $token->delete();
            }

            return $valid;
        });

        RateLimiter::for('email-delivery-status', fn ($request) => Limit::perMinute(60)->by($request->ip()));

        foreach (['registration-verify' => [10, 1], 'registration-resend' => [3, 10]] as $name => [$attempts, $minutes]) {
            RateLimiter::for($name, fn ($request) => Limit::perMinutes($minutes, $attempts)
                ->by($request->ip())
                ->response(fn ($request, $headers) => response()->json([
                    'success' => false,
                    'message' => $name === 'registration-resend'
                        ? 'Please wait before requesting another code.'
                        : 'Too many attempts. Please request a new verification code.',
                ], 429, $headers)));
        }
    }
}
