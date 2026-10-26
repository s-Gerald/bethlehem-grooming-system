<?php

namespace App\Services;

use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OperationalCapacity
{
    public const MAX_PETS = 20;

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

    public function snapshot(): array
    {
        $used = Schema::hasTable('booking_pets') ? $this->onSiteGroomingPets()->count() : 0;

        return [
            'current' => $used,
            'used' => $used,
            'max' => self::MAX_PETS,
            'remaining' => max(0, self::MAX_PETS - $used),
            'percent' => min(100, round($used / self::MAX_PETS * 100)),
            'is_full' => $used >= self::MAX_PETS,
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

        if ($service === 'grooming' && $this->snapshot()['used'] + $pets > self::MAX_PETS) {
            throw ValidationException::withMessages([
                'check_in' => 'Grooming capacity is full or cannot accommodate all these pets. Please wait until space is available.',
            ]);
        }
    }
}
