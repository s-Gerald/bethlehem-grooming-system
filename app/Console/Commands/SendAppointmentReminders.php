<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\ClinicAppointment;
use App\Models\CustomerNotification;
use App\Services\PreRegistrationExpiry;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'reminders:send';
    protected $description = 'Send valid-day reminders for Clinic and Grooming pre-registrations.';

    public function handle(PreRegistrationExpiry $expiry): void
    {
        $expiry->expire();
        $settings = \App\Models\ClinicSetting::current();
        Booking::where('status', 'waiting_to_arrive')->whereBetween('booking_date', [now()->toDateString(), now()->addDay()->toDateString()])
            ->with(['user', 'timeWindow', 'bookingPets.pet'])
            ->chunkById(100, function ($bookings) use ($settings) {
                foreach ($bookings as $booking) {
                    $this->remind($booking, 'grooming', $booking->booking_date, $this->petNames($booking), $settings->serviceAvailability('grooming')['open_time']);
                }
            }, 'booking_id');
        ClinicAppointment::where('status', 'waiting_to_arrive')->whereBetween('appointment_date', [now()->toDateString(), now()->addDay()->toDateString()])
            ->with(['user', 'timeWindow', 'pet'])
            ->chunkById(100, function ($visits) use ($settings) {
                foreach ($visits as $visit) {
                    $this->remind($visit, 'clinic', $visit->appointment_date->toDateString(), $visit->pet?->pet_name ?? 'your pet', $settings->serviceAvailability('clinic')['open_time']);
                }
            });
        $this->info('Pre-registration reminders processed.');
    }

    private function remind(Booking|ClinicAppointment $registration, string $service, string $date, string $pets, string $openTime): void
    {
        if (! $registration->user) {
            return;
        }
        // Operating time schedules the reminder only; it is never an assigned arrival.
        $time = $registration->timeWindow?->start_time
            ?? $openTime;
        $arrival = Carbon::parse($date.' '.$time);
        $minutes = now()->diffInMinutes($arrival, false);
        $period = match (true) {
            $minutes >= 1380 && $minutes <= 1500 => '24h',
            $minutes >= 150 && $minutes <= 210 => '3h',
            default => null,
        };
        if ($period === null) {
            return;
        }

        $foreignKey = $service === 'clinic' ? 'clinic_appointment_id' : 'booking_id';
        $type = ($service === 'clinic' ? 'clinic_' : '').'reminder_'.$period;
        $window = $registration->timeWindow?->displayLabel();
        $when = $period === '24h' ? 'tomorrow' : 'today';
        $plan = $service === 'clinic' ? 'clinic arrival' : 'grooming drop-off';
        CustomerNotification::firstOrCreate([
            'user_id' => $registration->user->user_id,
            $foreignKey => $registration->getKey(),
            'type' => $type,
        ], [
            'message' => $window
                ? "Reminder: You planned {$pets}'s {$plan} {$when} around {$window}. Your pet joins the queue after check-in."
                : "Reminder: Your {$service} registration for {$pets} is valid {$when}. Show your registration reference when you arrive.",
            'is_read' => false,
            'created_at' => now(),
        ]);
    }

    private function petNames(Booking $booking): string
    {
        $names = $booking->bookingPets->map(fn ($pet) => $pet->pet?->pet_name)
            ->filter()->unique()->values();

        return $names->isEmpty() ? 'your pet' : $names->join(', ', ' and ');
    }
}
