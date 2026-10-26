<?php

namespace App\Providers;

use App\Services\PasswordResetService;
use App\Services\RegistrationEmailCodeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
                $token->delete();
            }

            return $valid;
        });

        RateLimiter::for('email-delivery-status', fn ($request) => Limit::perMinute(60)->by($request->ip()));

        foreach (['registration-resend' => RegistrationEmailCodeService::COOLDOWN_SECONDS,
            'password-resend' => PasswordResetService::COOLDOWN_SECONDS] as $name => $seconds) {
            RateLimiter::for($name, function ($request) use ($seconds): array {
                $emailKey = hash('sha256', Str::lower(trim((string) $request->input('email'))));
                return [
                    Limit::perMinutes(10, 3)->by('ip:'.$request->ip()),
                    Limit::perMinutes(10, 3)->by('email:'.$emailKey),
                    Limit::perSecond(1, $seconds)->by('cooldown:'.$emailKey),
                ];
            });
        }

        RateLimiter::for('registration-verify', fn ($request) => Limit::perMinute(10)
            ->by($request->ip())
            ->response(fn ($request, $headers) => response()->json([
                'success' => false,
                'message' => 'Too many attempts. Try again later.',
            ], 429, $headers)));
    }
}
