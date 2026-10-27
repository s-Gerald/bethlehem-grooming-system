<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\Booking;
use App\Models\ClinicAppointment;
use App\Models\ClinicSetting;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreRegistrationExpiry
{
    public function expire(?int $userId = null): int
    {
        $count = 0;
        $now = Carbon::now('Asia/Manila');
        foreach ([
            [Booking::class, 'bookings', 'booking_id', 'booking_date', 'dropped_off_at', 'grooming', Notification::TYPE_NO_SHOW],
            [ClinicAppointment::class, 'clinic_appointments', 'id', 'appointment_date', 'checked_in_at', 'clinic', Notification::TYPE_CLINIC_NO_SHOW],
        ] as [$model, $table, $key, $date, $checkIn, $service, $type]) {
            $columns = Schema::getColumnListing($table);
            if (array_diff([$date, $checkIn], $columns) !== []) {
                continue;
            }
            $hasDeadline = in_array('registration_closes_at', $columns, true);
            $hasCreatedAt = in_array('created_at', $columns, true);
            $hasExemption = in_array('registration_no_show_exempt_at', $columns, true);
            $hasPaid = in_array('paid', $columns, true);
            $lastKey = 0;

            // Lock and notify in the same transaction so overlapping expiry/read
            // requests cannot notify twice or race a reschedule/check-in.
            do {
                [$expired, $scanned, $nextKey] = DB::transaction(function () use ($model, $table, $key, $date, $checkIn, $service, $type, $userId, $now, $hasDeadline, $hasCreatedAt, $hasExemption, $hasPaid, $lastKey): array {
                    // Same lock order as staff intake and availability changes.
                    $settings = ClinicSetting::current(lockForUpdate: true);
                    $hours = $settings->serviceAvailability($service);
                    if ($hours['open_time'] >= $hours['close_time']) return [0, 0, $lastKey];
                    $lastDay = $now->format('H:i') >= $hours['close_time'] ? $now->toDateString() : $now->copy()->subDay()->toDateString();
                    $query = $model::query()->where('status', 'waiting_to_arrive')->whereNull($checkIn)
                        ->whereNull('queue_number')->where($date, '<=', $lastDay)->where($key, '>', $lastKey)
                        ->when($userId !== null, fn ($query) => $query->where('user_id', $userId));
                    if ($hasDeadline) $query->where(fn ($q) => $q->whereNull('registration_closes_at')->orWhere('registration_closes_at', '<=', $now));
                    if ($hasExemption) $query->whereNull('registration_no_show_exempt_at');
                    // Retain interrupted visits for staff resolution, even after intake reopens.
                    $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clinic_closures')
                        ->whereIn('type', ['blocked_date', 'stop_today'])
                        ->where(fn ($q) => $q->whereNull('service')->orWhere('service', $service))
                        ->whereColumn('start_date', '<=', $table.'.'.$date)->whereColumn('end_date', '>=', $table.'.'.$date)
                        ->where(fn ($q) => $q->where('is_active', true)->orWhereColumn('updated_at', '>=', $table.'.'.$date)));
                    if ($service === 'grooming' && Schema::hasTable('booking_pets')) {
                        $query->whereDoesntHave('bookingPets', fn ($q) => $q->whereNotNull('grooming_start_time')
                            ->orWhereNotNull('grooming_end_time')->orWhereIn('grooming_state', ['in_progress', 'finished']));
                    }
                    if ($hasPaid) $query->where(fn ($q) => $q->whereNull('paid')->orWhere('paid', false));
                    $registrations = $query->orderBy($key)->limit(200)->lockForUpdate()->get();
                    $scanned = $registrations->count();
                    if ($scanned === 0) return [0, 0, $lastKey];
                    $nextKey = $registrations->last()->getKey();
                    // Shortened hours interrupted the promised intake period; staff must resolve it.
                    $registrations = $registrations->reject(function ($row) use ($hours, $hasCreatedAt, $settings) {
                        $deadline = $row->getAttribute('registration_closes_at');
                        if ($deadline) return Carbon::parse($deadline, 'Asia/Manila')->format('H:i') > $hours['close_time'];
                        // Legacy rows have no saved promise. Changed hours need staff review.
                        return $hasCreatedAt && $row->getAttribute('created_at') && $settings->updated_at
                            && $settings->updated_at->gt(Carbon::parse($row->getAttribute('created_at'), 'Asia/Manila'));
                    });
                    if ($registrations->isEmpty()) return [0, $scanned, $nextKey];
                    DB::table($table)->whereIn($key, $registrations->modelKeys())->update(['status' => 'no_show']);
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
                            'type' => CustomerNotification::TYPE_GROOMING_NO_SHOW,
                            'message' => "Your Grooming Online Registration for {$label} was marked No Show because no check-in was recorded before closing.",
                            'is_read' => false, 'created_at' => now()];
                    })->all() : [];
                    if ($notifications !== []) CustomerNotification::insert($notifications);

                    return [$registrations->count(), $scanned, $nextKey];
                }, 5);
                $lastKey = $nextKey;
                $count += $expired;
            } while ($scanned === 200);
        }

        return $count;
    }
}
