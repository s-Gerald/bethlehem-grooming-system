<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicSetting;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DailyPetQueue
{
    /**
     * Serialize Clinic and Grooming intake, including across midnight.
     */
    public function runForDate(string $queueDate, Closure $callback): mixed
    {
        $lockName = 'bethlehem-shared-intake';
        $usesMysqlLock = DB::getDriverName() === 'mysql';

        if ($usesMysqlLock) {
            $result = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);

            if ((int) ($result->acquired ?? 0) !== 1) {
                throw new RuntimeException("Could not acquire the pet queue lock for {$queueDate}.");
            }
        }

        try {
            return DB::transaction(function () use ($callback) {
                // Lock before reading any queue/capacity data. All intake paths
                // use this same singleton, including on non-MySQL databases.
                ClinicSetting::current(lockForUpdate: true);

                return $callback();
            }, 5);
        } finally {
            if ($usesMysqlLock) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        }
    }

    /**
     * Give every pet in a booking its immutable number for the effective date.
     *
     * @return array<int, int> booking_pet_id => pet_queue_number
     */
    public function assignBookingPets(Booking $booking, string $queueDate, int $firstPetNumber): array
    {
        $bookingPets = BookingPet::with('pet')
            ->where('booking_id', $booking->booking_id)
            ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
            ->lockForUpdate()
            ->get()
            ->sort(function (BookingPet $left, BookingPet $right): int {
                $rank = static function (BookingPet $bookingPet): int {
                    return match (strtolower((string) $bookingPet->pet?->species)) {
                        'dog' => 0,
                        'cat' => 1,
                        default => 2,
                    };
                };

                return $rank($left) <=> $rank($right)
                    ?: $left->booking_pet_id <=> $right->booking_pet_id;
            });

        $nextNumber = $firstPetNumber;

        $assigned = [];

        foreach ($bookingPets as $bookingPet) {
            $alreadyAssignedToday = $bookingPet->pet_queue_date?->toDateString() === $queueDate
                && (int) $bookingPet->pet_queue_number > 0;

            if (! $alreadyAssignedToday) {
                $bookingPet->update([
                    'pet_queue_date' => $queueDate,
                    'pet_queue_number' => $nextNumber++,
                ]);
            }

            $assigned[$bookingPet->booking_pet_id] = (int) $bookingPet->pet_queue_number;
        }

        return $assigned;
    }
}
