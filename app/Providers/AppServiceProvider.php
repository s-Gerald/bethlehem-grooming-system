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
        $this->app->scoped(\App\Services\GroomingServiceSelection::class);
        $this->app->scoped(\App\Services\GroomingServiceSelection::class);
        $this->app->scoped(\App\Services\ActivityRecorder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\Booking::class, \App\Models\BookingPet::class, \App\Models\ClinicAppointment::class,
            \App\Models\Payment::class, \App\Models\PosTransaction::class, \App\Models\InventoryItem::class,
            \App\Models\Service::class, \App\Models\ClinicService::class, \App\Models\Pet::class,
            \App\Models\UnregisteredCustomer::class, \App\Models\User::class, \App\Models\ClinicRecord::class,
            \App\Models\VaccinationRecord::class, \App\Models\ClinicSetting::class, \App\Models\QrPaymentSettings::class,
            \App\Models\ClinicClosure::class, \App\Models\PetGroomer::class, \App\Models\BookingService::class,
            \App\Models\ClinicVital::class, \App\Models\ClinicCaseService::class, \App\Models\ClinicCharge::class,
            \App\Models\ClinicConfinement::class, \App\Models\ClinicConfinementObservation::class,
            \App\Models\ClinicAttachment::class] as $model) {
            $model::observe(\App\Observers\ActivityObserver::class);
        }
        // Sanctum rejects expired tokens before route middleware can revoke them.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(function ($token, bool $valid): bool {
            if (! $valid) {
                app(\App\Services\RememberedDeviceService::class)->checkpointSessionActivity($token);
                $token->delete();
            }

            return $valid;
        });

        $this->app['events']->listen(\Illuminate\Console\Events\CommandStarting::class, function ($event): void {
            if ($event->command === 'sanctum:prune-expired') {
                app(\App\Services\RememberedDeviceService::class)->checkpointRetainedSessionActivity();
            }
        });

        RateLimiter::for('email-delivery-status', fn ($request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('remembered-session', fn ($request) => [
            Limit::perMinute(60)->by('remember-ip:'.$request->ip()),
            Limit::perMinute(15)->by('remember-device:'.hash('sha256', (string) (
                $request->cookie(\App\Services\RememberedDeviceService::COOKIE)
                ?: $request->cookie(\App\Http\Middleware\UseAccountSessionCookie::NAME) ?: $request->ip()
            ))),
        ]);

        RateLimiter::for('registration-verify', fn ($request) => Limit::perMinute(10)
            ->by($request->ip())
            ->response(fn ($request, $headers) => response()->json([
                'success' => false,
                'message' => 'Too many attempts. Try again later.',
            ], 429, $headers)));
    }
}
