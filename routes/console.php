<?php

use App\Models\LoginEmailChallenge;
use App\Models\PendingCustomerRegistration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Preferred arrival times never expire a registration during its selected day.
Schedule::command('pre-registrations:expire')->everyFiveMinutes()->withoutOverlapping();

// Send preferred-arrival reminders every 30 minutes
Schedule::command('reminders:send')->everyThirtyMinutes();

// Send hourly pickup reminders for released bookings
Schedule::command('pickups:remind')->hourly();

// Remove expired bearer-token rows after their configured lifetime.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Pending signups are not customer accounts and are retained only long enough
// for email verification and resend recovery.
Schedule::call(function (): void {
    PendingCustomerRegistration::pruneExpired();
})
    ->name('pending-customer-registrations:prune')
    ->daily()
    ->withoutOverlapping();

Schedule::call(function (): void {
    LoginEmailChallenge::query()
        ->where('expires_at', '<', now())
        ->delete();
})
    ->name('login-email-challenges:prune')
    ->hourly()
    ->withoutOverlapping();

Artisan::command('qr-payments:prune-orphans', function () {
    $this->info('Removed '.app(\App\Services\QrPaymentProofService::class)->pruneOrphans().' abandoned payment proofs.');
})->purpose('Remove unreferenced payment uploads older than 24 hours; retain recorded proofs');

Schedule::command('qr-payments:prune-orphans')->daily()->withoutOverlapping();
