<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\Booking;
use App\Models\ClinicAppointment;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreRegistrationExpiry
{
    public function expire(?int $userId = null): int
    {
        $count = 0;
        $today = now()->toDateString();
        foreach ([
            [Booking::class, 'bookings', 'booking_id', 'booking_date', 'dropped_off_at', 'grooming', Notification::TYPE_NO_SHOW],
            [ClinicAppointment::class, 'clinic_appointments', 'id', 'appointment_date', 'checked_in_at', 'clinic', Notification::TYPE_CLINIC_NO_SHOW],
        ] as [$model, $table, $key, $date, $checkIn, $service, $type]) {
            if (! Schema::hasColumns($table, [$date, $checkIn])) {
                continue;
            }
            $unused = fn () => $model::query()
                ->where('status', 'waiting_to_arrive')
                ->whereNull($checkIn)
                ->where($date, '<', $today)
                ->when($userId !== null, fn ($query) => $query->where('user_id', $userId));

            // Lock and notify in the same transaction so overlapping expiry/read
            // requests cannot notify twice or race a reschedule/check-in.
            do {
                $expired = DB::transaction(function () use ($unused, $table, $key, $service, $type): int {
                    $registrations = $unused()->orderBy($key)->limit(200)->lockForUpdate()->get();
                    if ($registrations->isEmpty()) return 0;
                    DB::table($table)->whereIn($key, $registrations->modelKeys())->update(['status' => 'expired']);
                    app(ActivityRecorder::class)->recordExpirations($registrations, $service);

                    if (Schema::hasTable('notifications')) {
                        $registrations->load($service === 'grooming' ? ['user', 'walkin', 'bookingPets.pet'] : ['user', 'walkin', 'pet']);
                        Notification::insert($registrations->map(fn ($registration) => [
                            'type' => $type,
                            ($service === 'grooming' ? 'booking_id' : 'clinic_appointment_id') => $registration->getKey(),
                            'message' => Notification::noShowMessage($registration, $service),
                            'is_read' => false, 'created_at' => now(),
                        ])->all());
                    }

                    $notifications = $service === 'grooming' ? $registrations->whereNotNull('user_id')->map(function ($booking) {
                        $day = Carbon::parse($booking->booking_date);
                        $label = $day->format($day->year === now()->year ? 'M j' : 'M j, Y');
                        return ['user_id' => $booking->user_id, 'booking_id' => $booking->booking_id,
                            'type' => CustomerNotification::TYPE_GROOMING_PRE_REGISTRATION_EXPIRED,
                            'message' => "Your grooming online registration for {$label} was cancelled because the scheduled date passed without check-in.",
                            'is_read' => false, 'created_at' => now()];
                    })->all() : [];
                    if ($notifications !== []) CustomerNotification::insert($notifications);

                    return $registrations->count();
                }, 5);
                $count += $expired;
            } while ($expired === 200);
        }

        return $count;
    }
}
