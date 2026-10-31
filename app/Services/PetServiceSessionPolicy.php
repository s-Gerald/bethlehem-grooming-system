<?php

namespace App\Services;

use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\CustomerNotification;
use App\Models\Notification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;

class PetServiceSessionPolicy
{
    public const TERMINAL_GROOMING_STATUSES = ['archived', 'completed', 'cancelled', 'no_show', 'expired'];

    /** Batched persisted-pet lookup shared by display and intake enforcement. */
    public function conflicts(array $petIds, string $service): \Illuminate\Support\Collection
    {
        if (!$petIds) return collect();
        if ($service === 'grooming') {
            if (!Schema::hasTable('clinic_appointments')) return collect();
            return ClinicAppointment::with('pet')->whereIn('pet_id', $petIds)->blocking()->get();
        }
        if (!Schema::hasTable('booking_pets')) return collect();
        return BookingPet::with(['booking', 'pet'])->whereIn('pet_id', $petIds)
            ->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', self::TERMINAL_GROOMING_STATUSES))->get();
    }

    public function replaceable($record, string $service): bool
    {
        if ($service === 'grooming') {
            return $record->status === 'waiting_to_arrive' && $record->appointment_type === 'pre_registered'
                && !$record->checked_in_at && !$record->queue_number && !$record->paid && !$record->consultation_started_at && !$record->consultation_finished_at;
        }
        $booking = $record->booking;
        return $booking->status === 'waiting_to_arrive' && $booking->booking_type === 'online'
            && !$booking->walkin_id && !$booking->dropped_off_at && !$booking->queue_number && !$booking->paid
            && !$booking->grooming_started_at && !$booking->grooming_finished_at
            && $record->grooming_state === BookingPet::GROOMING_STATE_NOT_STARTED
            && !$record->grooming_start_time && !$record->grooming_end_time;
    }

    /** Call inside DailyPetQueue, after ownership validation and before committing intake. */
    public function enforce(array $petIds, string $service, bool $physicalIntake = false): void
    {
        $conflicts = $this->conflicts($petIds, $service);
        foreach ($conflicts as $record) {
            if (!$physicalIntake || !$this->replaceable($record, $service)) {
                $label = $service === 'grooming' ? 'Clinic' : 'Grooming';
                throw new HttpResponseException(response()->json(['success' => false, 'code' => 'pet_service_conflict',
                    'message' => "This pet already has a pending or active {$label} visit. Resolve that visit before intake.",
                    'pet_id' => $record->pet_id], 409));
            }
        }
        foreach ($conflicts as $record) $this->cancelPending($record, $service);
    }

    private function cancelPending($record, string $service): void
    {
        $to = $service === 'grooming' ? 'Grooming' : 'Clinic';
        $from = $service === 'grooming' ? 'Clinic' : 'Grooming';
        $reason = "Checked in for {$to}.";
        if ($service === 'grooming') {
            \App\Models\GatewayPayment::assertClinicUnlocked($record);
            $record->update(['status' => 'cancelled']);
            $ownerId = $record->user_id;
            $pet = $record->pet;
            $reference = ['clinic_appointment_id' => $record->id];
            Notification::createForClinic($record, Notification::TYPE_CLINIC_CANCELLED, "Clinic registration {$record->appointment_reference} cancelled: {$reason}");
        } else {
            $booking = $record->booking;
            \App\Models\GatewayPayment::assertGroomingUnlocked($booking);
            if ($booking->hasSuccessfulPayment()) throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'Paid grooming visits cannot be cancelled.']);
            $audit = ['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancellation_reason' => $reason];
            $record->update($audit + ['grooming_state' => BookingPet::GROOMING_STATE_CANCELLED]);
            if (!$booking->bookingPets()->where('grooming_state', '!=', BookingPet::GROOMING_STATE_CANCELLED)->exists()) {
                $booking->cancelWithAudit($audit);
            }
            $ownerId = $booking->user_id;
            $pet = $record->pet;
            $reference = ['booking_id' => $booking->booking_id];
        }
        if ($ownerId) CustomerNotification::create($reference + ['user_id' => $ownerId, 'pet_id' => $record->pet_id,
            'type' => CustomerNotification::TYPE_BOOKING_CANCELLED,
            'message' => "{$pet->pet_name}'s {$from} online registration was cancelled because {$pet->pet_name} was checked in for {$to}.",
            'is_read' => false, 'created_at' => now()]);
    }

    public function availability(array $petIds): array
    {
        $result = [];
        foreach (['grooming', 'clinic'] as $service) {
            foreach ($this->conflicts($petIds, $service) as $record) {
                $replaceable = $this->replaceable($record, $service);
                $from = $service === 'grooming' ? 'Clinic' : 'Grooming';
                $result[$record->pet_id][$service] = ['allowed' => false,
                    'replaceable' => ($result[$record->pet_id][$service]['replaceable'] ?? true) && $replaceable,
                    'label' => (($result[$record->pet_id][$service]['replaceable'] ?? true) && $replaceable) ? "{$from} Pending" : "In {$from}"];
            }
        }
        return $result;
    }
}
