<?php

namespace App\Services;

use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\ClinicSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
        $maximum = ($settings ?? ClinicSetting::current())->grooming_cage_capacity ?? ClinicSetting::DEFAULT_CAGE_CAPACITY;
        $confined = Schema::hasTable('clinic_appointments') && Schema::hasTable('clinic_confinements')
            ? ClinicAppointment::confined() : null;
        $grooming = Schema::hasTable('booking_pets') ? $this->onSiteGroomingPets() : null;

        if ($grooming && $confined) {
            $grooming->whereNotExists((clone $confined)->selectRaw('1')
                ->whereColumn('clinic_appointments.pet_id', 'booking_pets.pet_id'));
        }

        // Count known pets once and retain occupancy for legacy rows without a pet ID.
        $countPets = 'COUNT(DISTINCT pet_id) + COUNT(*) - COUNT(pet_id)';
        $counts = DB::query()
            ->selectSub($grooming?->selectRaw($countPets) ?? DB::query()->selectRaw('0'), 'grooming')
            ->selectSub($confined?->selectRaw($countPets) ?? DB::query()->selectRaw('0'), 'confined')
            ->first();
        $used = (int) $counts->grooming + (int) $counts->confined;

        return [
            'grooming' => (int) $counts->grooming,
            'confined' => (int) $counts->confined,
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

        if ($service === 'grooming') {
            $this->assertCagesAvailable($pets, 'check_in', 'There is not enough cage space for this check-in. Please wait until space is available.');
        }
    }

    /** Call under the shared intake lock before creating physical occupancy. */
    public function assertCagesAvailable(int $pets, string $field, string $message): void
    {
        if ($pets > $this->snapshot()['remaining']) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
