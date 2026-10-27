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

    /**
     * A customer may start either flow only when neither service has an
     * ongoing registration. Historical visit counts are intentionally ignored.
     */
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

        if ($grooming) {
            return $this->blockedResult(
                'grooming',
                'Grooming',
                $grooming->booking_reference,
                $grooming->status,
            );
        }

        $clinic = Schema::hasTable('clinic_appointments')
            ? ClinicAppointment::query()
                ->where('user_id', $userId)
                ->blocking()
                ->orderByDesc('id')
                ->first(['id', 'appointment_reference', 'status'])
            : null;

        if ($clinic) {
            return $this->blockedResult(
                'clinic',
                'Clinic',
                $clinic->appointment_reference,
                $clinic->status,
            );
        }

        return [
            'allowed' => true,
            'has_ongoing_registration' => false,
            'ongoing' => null,
            'message' => null,
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
            'has_ongoing_registration' => true,
            'ongoing' => [
                'type' => $type,
                'label' => $label,
                'reference' => $reference,
                'status' => $status,
            ],
            'message' => 'You already have an ongoing registration.',
        ];
    }
}
