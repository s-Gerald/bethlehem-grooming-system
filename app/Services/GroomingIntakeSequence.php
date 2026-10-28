<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GroomingIntakeSequence
{
    /** Call only inside DailyPetQueue's shared intake lock and transaction. */
    public function current(string $queueDate): object
    {
        $sequence = DB::table('grooming_intake_sequences')->where('queue_date', $queueDate)->lockForUpdate()->first();
        if ($sequence) {
            return $sequence;
        }

        // Seed once per date from immutable references as well as retained queues.
        // Include canceled/archived history and compare numeric suffixes beyond 999.
        $prefix = 'WI-'.str_replace('-', '', $queueDate).'-';
        $lastReference = 0;
        foreach (DB::table('bookings')->where('booking_reference', 'like', $prefix.'%')->pluck('booking_reference') as $reference) {
            $suffix = substr($reference, strlen($prefix));
            if (ctype_digit($suffix)) {
                $lastReference = max($lastReference, (int) $suffix);
            }
        }
        $sequence = (object) [
            'queue_date' => $queueDate,
            'last_booking_number' => max($lastReference, (int) DB::table('bookings')->where('booking_date', $queueDate)->max('queue_number')),
            'last_pet_number' => (int) DB::table('booking_pets')->where('pet_queue_date', $queueDate)->max('pet_queue_number'),
        ];
        DB::table('grooming_intake_sequences')->insert((array) $sequence);

        return $sequence;
    }

    /** @return array{queue_number: int, first_pet_number: int, booking_reference: string} */
    public function reserve(string $queueDate, int $petCount): array
    {
        $sequence = $this->current($queueDate);
        $queueNumber = (int) $sequence->last_booking_number + 1;
        $firstPetNumber = (int) $sequence->last_pet_number + 1;
        DB::table('grooming_intake_sequences')->where('queue_date', $queueDate)->update([
            'last_booking_number' => $queueNumber,
            'last_pet_number' => (int) $sequence->last_pet_number + $petCount,
        ]);

        return [
            'queue_number' => $queueNumber,
            'first_pet_number' => $firstPetNumber,
            'booking_reference' => 'WI-'.str_replace('-', '', $queueDate).'-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT),
        ];
    }
}
