<?php

namespace App\Services;

use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\ClinicSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OperationalCapacity
{
    public function onSiteGroomingPets(): Builder
    {
        // Released means paid and ready for pickup. Physical pickup archives the booking.
        return BookingPet::query()->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
            ->whereHas('booking', fn (Builder $booking) => $booking
            ->where(function (Builder $onSite) {
                $onSite->whereIn('status', ['checked_in', 'in_progress', 'for_payment', 'released'])
                    ->orWhere(function (Builder $legacyPickup) {
                        // Legacy pickup statuses alone do not prove physical intake.
                        $legacyPickup->where('status', 'for_pickup')
                            ->where(function (Builder $arrived) {
                                $arrived->whereNotNull('dropped_off_at')
                                    ->orWhereHas('bookingPets', fn (Builder $pet) => $pet
                                        ->whereNotNull('grooming_start_time')
                                        ->orWhereNotNull('grooming_end_time'));
                            });
                    });
            }));
    }

    public function groomingPets(): Builder
    {
        return BookingPet::query()
            ->whereNull('grooming_end_time')
            ->whereNotIn('grooming_state', [BookingPet::GROOMING_STATE_FINISHED, BookingPet::GROOMING_STATE_CANCELLED])
            ->whereHas('booking', fn (Builder $booking) => $booking
                ->whereIn('status', ['checked_in', 'in_progress']));
    }

    public function snapshot(?ClinicSetting $settings = null): array
    {
        $used = Schema::hasTable('booking_pets') ? $this->onSiteGroomingPets()->count() : 0;
        $maximum = ($settings ?? ClinicSetting::current())->grooming_cage_capacity ?? ClinicSetting::DEFAULT_CAGE_CAPACITY;

        return [
            'current' => $used,
            'used' => $used,
            'max' => $maximum,
            'remaining' => max(0, $maximum - $used),
            'percent' => min(100, round($used / $maximum * 100)),
            'is_full' => $used >= $maximum,
        ];
    }

    public function waitingCount(): int
    {
        $grooming = Schema::hasTable('booking_pets') ? $this->groomingPets()
            ->whereNull('grooming_start_time')
            ->where('grooming_state', BookingPet::GROOMING_STATE_NOT_STARTED)->count() : 0;
        $clinic = Schema::hasTable('clinic_appointments') ? ClinicAppointment::query()
            ->where('status', 'checked_in')->count() : 0;

        return $grooming + $clinic;
    }

    /** Called inside DailyPetQueue's shared intake transaction, before any intake writes. */
    public function assertCanAccept(string $service, int $pets): void
    {
        if (! app(RegistrationServiceDay::class)->acceptingCheckIns($service)) {
            throw ValidationException::withMessages([
                'check_in' => 'Bethlehem is not accepting physical check-ins for this service right now.',
            ]);
        }

        $capacity = $service === 'grooming' ? $this->snapshot() : null;
        if ($capacity && $capacity['used'] + $pets > $capacity['max']) {
            throw ValidationException::withMessages([
                'check_in' => 'Grooming capacity is full or cannot accommodate all these pets. Please wait until space is available.',
            ]);
        }
    }
}
