<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\GatewayPayment;
use App\Models\Notification;

class ClinicPaymentSettlementService
{
    /** Caller holds the case lock and has validated cash or a signed paid webhook. */
    public function settle(ClinicAppointment $appointment, string $total, string $method, ?int $actorId, ?string $cash = null, ?GatewayPayment $gateway = null): void
    {
        $appointment->update([
            'status' => 'completed', 'total_amount' => $total, 'paid' => true,
            'payment_method' => $method, 'cash_received' => $cash,
            'paid_by_user_id' => $actorId, 'paid_at' => $gateway?->paid_at ?? now(),
            ...($gateway ? ['gateway_payment_id' => $gateway->id] : []),
        ]);
        Notification::createForClinic($appointment, Notification::TYPE_CLINIC_PAYMENT_CONFIRMED,
            "Payment received for clinic appointment {$appointment->appointment_reference}.");
    }
}
