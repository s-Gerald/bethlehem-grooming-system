<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClinicAppointment;
use Illuminate\Support\Facades\Schema;

class CustomerPreRegistrationAccessService
{
    private const TERMINAL_GROOMING_STATUSES = [
        'archived',
        'completed',
        'cancelled',
        'no_show',
        'expired',
    ];

    /** Return account-level eligibility independently for each service. */
    public function forUser(int $userId): array
    {
        app(PreRegistrationExpiry::class)->expire($userId);
        $grooming = Schema::hasTable('bookings')
            ? Booking::query()
                ->where('user_id', $userId)
                ->whereNotIn('status', self::TERMINAL_GROOMING_STATUSES)
                ->orderByDesc('booking_id')
                ->first(['booking_id', 'booking_reference', 'status'])
            : null;

        $clinic = Schema::hasTable('clinic_appointments')
            ? ClinicAppointment::query()
                ->where('user_id', $userId)
                ->blocking()
                ->orderByDesc('id')
                ->first(['id', 'appointment_reference', 'status'])
            : null;

        $services = [
            'grooming' => $grooming
                ? $this->blockedResult('grooming', 'Grooming', $grooming->booking_reference, $grooming->status)
                : ['allowed' => true, 'ongoing' => null, 'message' => null],
            'clinic' => $clinic
                ? $this->blockedResult('clinic', 'Clinic', $clinic->appointment_reference, $clinic->status)
                : ['allowed' => true, 'ongoing' => null, 'message' => null],
        ];

        return [
            'allowed' => $services['grooming']['allowed'] || $services['clinic']['allowed'],
            'services' => $services,
        ];
    }

    private function blockedResult(
        string $type,
        string $label,
        ?string $reference,
        string $status,
    ): array {
        return [
            'allowed' => false,
            'ongoing' => [
                'type' => $type,
                'label' => $label,
                'reference' => $reference,
                'status' => $status,
            ],
            'message' => "You already have an active {$label} registration.",
        ];
    }
}
