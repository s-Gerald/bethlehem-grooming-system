<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicSetting;
use Carbon\Carbon;

class ClinicAppointmentSequence
{
    /**
     * Reserve the next clinic appointment reference and optional queue number.
     * Call this from the transaction that creates or checks in the appointment.
     *
     * @return array{appointment_reference: string, queue_number: ?int}
     */
    public function reserve(string $appointmentDate, bool $includeQueue): array
    {
        return $this->reserveBatch($appointmentDate, $includeQueue, 1)[0];
    }

    public function reserveBatch(string $appointmentDate, bool $includeQueue, int $count): array
    {
        ClinicSetting::current(lockForUpdate: true);

        $date = Carbon::parse($appointmentDate);
        $prefix = 'CL-'.$date->format('Ymd').'-';
        $lastReference = ClinicAppointment::query()
            ->where('appointment_reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('appointment_reference');
        $nextReferenceNumber = $lastReference
            ? ((int) substr($lastReference, strlen($prefix))) + 1
            : 1;

        $nextQueueNumber = $includeQueue
            ? ((int) ClinicAppointment::query()->whereDate('appointment_date', $appointmentDate)->max('queue_number')) + 1
            : null;

        return array_map(fn (int $offset) => [
            'appointment_reference' => $prefix.str_pad((string) ($nextReferenceNumber + $offset), 3, '0', STR_PAD_LEFT),
            'queue_number' => $nextQueueNumber === null ? null : $nextQueueNumber + $offset,
        ], range(0, $count - 1));
    }

    public function nextQueueNumber(string $appointmentDate): int
    {
        ClinicSetting::current(lockForUpdate: true);

        return ((int) ClinicAppointment::query()
            ->whereDate('appointment_date', $appointmentDate)
            ->max('queue_number')) + 1;
    }
}
