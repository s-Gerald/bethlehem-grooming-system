<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\GatewayPayment;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class GroomingPaymentSettlementService
{
    public function __construct(
        private readonly GroomingPaymentReadinessService $paymentReadiness,
    ) {}

    /** Caller holds the booking lock and transaction; payment input is already validated. */
    public function settle(
        Booking $booking, string $total, string $tendered, string $method,
        ?string $notes, ?User $actor, bool $early, array $productLines,
        ?GatewayPayment $gateway = null,
    ): array {
        $payment = $this->recordPaidPayment($booking, $total, $tendered, $method, $notes, $actor?->user_id);
        if ($gateway) {
            $payment->update(['payment_provider' => 'paymongo', 'provider_method' => $gateway->provider_method,
                'gateway_payment_id' => $gateway->id, 'paid_at' => $gateway->paid_at]);
        }
        if ($productLines !== []) {
            app(GroomingProductSaleService::class)->record($payment, $actor, $productLines);
        }

        $allPetsFinished = true;
        $remainingPets = 0;
        if ($early) {
            $pets = $booking->bookingPets()->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
                ->lockForUpdate()->get();
            $allPetsFinished = $pets->isNotEmpty() && $pets->every(fn ($pet) =>
                $pet->grooming_state === BookingPet::GROOMING_STATE_FINISHED && $pet->grooming_end_time !== null);
            $remainingPets = $pets->count() - $pets->where('grooming_state', BookingPet::GROOMING_STATE_FINISHED)->count();
        }
        $updates = ['paid' => true, 'total_amount' => $total];
        if (! $early || $allPetsFinished) {
            $updates['status'] = 'released';
            if ($early) $updates['grooming_finished_at'] = $booking->grooming_finished_at ?? now();
            else $updates['archived_at'] = null;
        }
        $booking->update($updates);
        Notification::create([
            'type' => 'payment_confirmed', 'booking_id' => $booking->booking_id,
            'message' => "Payment received for visit {$booking->booking_reference}.",
            'is_read' => false, 'created_at' => $payment->paid_at ?? now(),
        ]);
        return compact('payment', 'allPetsFinished', 'remainingPets');
    }

    /**
     * Create the shared paid-payment audit record used by grooming settlement.
     * The caller must hold the booking row lock and must
     * validate the authoritative total before calling this method.
     */
    public function recordPaidPayment(
        Booking $booking,
        string $finalTotal,
        string $amountTendered,
        string $paymentMethod,
        ?string $notes,
        ?int $processedBy,
    ): Payment {
        $attributes = [
            'booking_id' => $booking->booking_id,
            'total_amount' => $finalTotal,
            'amount_tendered' => $amountTendered,
            'change_amount' => $this->paymentReadiness->centsToMoney(
                $this->paymentReadiness->moneyToCents($amountTendered)
                    - $this->paymentReadiness->moneyToCents($finalTotal),
            ),
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
            'notes' => $notes,
            'paid_at' => now(),
        ];

        if (Schema::hasColumn('payments', 'processed_by')) {
            $attributes['processed_by'] = $processedBy;
        }

        return Payment::create($attributes);
    }

}
