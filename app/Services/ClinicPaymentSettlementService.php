<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\Notification;

class ClinicPaymentSettlementService
{
    /** Caller holds the case lock and has validated cash. */
    public function settle(ClinicAppointment $appointment, string $total, string $method, ?int $actorId, ?string $cash = null): void
    {
        $appointment->update([
            'status' => 'completed', 'total_amount' => $total, 'paid' => true,
            'payment_method' => $method, 'cash_received' => $cash,
            'paid_by_user_id' => $actorId, 'paid_at' => now(),
        ]);
        Notification::createForClinic($appointment, Notification::TYPE_CLINIC_PAYMENT_CONFIRMED,
            "Payment received for clinic appointment {$appointment->appointment_reference}.");
    }
}
