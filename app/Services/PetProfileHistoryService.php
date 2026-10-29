<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\ClinicAppointment;
use App\Models\Pet;
use App\Models\VaccinationRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PetProfileHistoryService
{
    public function eligibleClinicalCases(int $petId)
    {
        return ClinicAppointment::query()->where('pet_id', $petId)
            ->whereIn('status', ['for_payment', 'completed'])
            ->where(fn ($query) => $query->whereHas('record')
                ->orWhereHas('vaccinationRecords', fn ($treatments) => $treatments->where('pet_id', $petId)->published()->notVoided())
                ->orWhereHas('caseServices', fn ($services) => $services->where('status', 'performed')));
    }

    public function clinicalHistory(Pet $pet)
    {
        // Union compact summaries before pagination; never hydrate every case or its attachments.
        $cases = $this->eligibleClinicalCases($pet->pet_id)
            ->leftJoin('clinic_records as record', 'record.clinic_appointment_id', '=', 'clinic_appointments.id')
            ->selectRaw("clinic_appointments.id, clinic_appointments.id as appointment_id,
                clinic_appointments.appointment_reference, clinic_appointments.appointment_date,
                COALESCE(record.chief_complaint, clinic_appointments.chief_complaint) as chief_complaint,
                COALESCE(record.working_diagnosis, record.diagnosis) as working_diagnosis, 'case' as record_type");
        $historical = VaccinationRecord::query()->where('pet_id', $pet->pet_id)
            ->whereNull('clinic_appointment_id')->published()->notVoided()
            ->selectRaw("id, NULL as appointment_id, NULL as appointment_reference,
                administered_date as appointment_date, NULL as chief_complaint,
                NULL as working_diagnosis, 'historical' as record_type");

        return DB::query()->fromSub($cases->unionAll($historical), 'history')
            ->orderByDesc('appointment_date')->orderByDesc('id')->orderBy('record_type')->paginate(20);
    }

    public function groomingRecords(Pet $pet): Collection
    {
        return Booking::query()
            ->whereHas('bookingPets', fn ($query) => $query->where('pet_id', $pet->pet_id))
            ->with([
                'timeWindow',
                'bookingPets' => fn ($query) => $query->where('pet_id', $pet->pet_id),
                'bookingServices.service',
            ])
            ->orderByDesc('booking_date')
            ->orderByDesc('booking_id')
            ->get()
            ->map(function (Booking $booking) {
                $bookingPet = $booking->bookingPets->first();

                if (! $bookingPet) {
                    return null;
                }

                $services = $booking->bookingServices
                    ->where('booking_pet_id', $bookingPet->booking_pet_id)
                    ->map(fn ($bookingService) => $bookingService->service?->service_name)
                    ->filter()
                    ->values();

                return [
                    'booking_reference' => $booking->booking_reference,
                    'booking_date' => $booking->booking_date,
                    'arrival_window' => $booking->preferredArrivalWindow()?->window_label,
                    'status' => $this->groomingStatus($booking, $bookingPet),
                    'grooming_started_at' => $bookingPet->grooming_start_time?->format('g:i A'),
                    'grooming_finished_at' => $bookingPet->grooming_end_time?->format('g:i A'),
                    'cancellation_reason' => $bookingPet->cancellation_reason ?? $booking->cancellation_reason,
                    'cancelled_at' => $bookingPet->cancelled_at?->toIso8601String() ?? $booking->cancelled_at?->toIso8601String(),
                    'services' => $services,
                    'paid' => (bool) $booking->paid,
                ];
            })
            ->filter()
            ->values();
    }

    public function medicalRecords(Pet $pet): Collection
    {
        return ClinicAppointment::query()
            ->select([
                'id',
                'appointment_reference',
                'appointment_date',
                'status',
                'pet_id',
                'chief_complaint',
            ])
            ->where('pet_id', $pet->pet_id)
            ->whereIn('status', ['for_payment', 'completed'])
            ->whereHas('record')
            ->with([
                'vitals:id,clinic_appointment_id,weight_kg,temperature_c,heart_rate_bpm,respiratory_rate_bpm,body_condition_score',
                'record',
                'record.attachments:id,clinic_record_id,file_name,file_type,file_size_bytes,label,created_at',
            ])
            ->orderByDesc('appointment_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ClinicAppointment $appointment) => $this->formatMedicalRecord($appointment))
            ->values();
    }

    public function vaccinationRecords(Pet $pet): Collection
    {
        return $pet->vaccinationRecords()
            ->visibleToClients()
            ->with([
                'clinicAppointment:id,appointment_reference',
            ])
            ->orderByDesc('administered_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VaccinationRecord $record) => $this->formatVaccinationRecord($record))
            ->values();
    }

    private function groomingStatus(Booking $booking, BookingPet $bookingPet): string
    {
        if (in_array($booking->status, ['cancelled', 'no_show', 'expired'], true)) {
            return $booking->status;
        }

        return match (true) {
            $bookingPet->grooming_state === BookingPet::GROOMING_STATE_CANCELLED => 'cancelled',
            $bookingPet->grooming_end_time !== null => 'grooming_finished',
            $bookingPet->grooming_start_time !== null => 'in_progress',
            default => $booking->status,
        };
    }

    public function formatMedicalRecord(ClinicAppointment $appointment): array
    {
        $record = $appointment->record;
        $vitals = $appointment->vitals;

        return [
            'appointment_id' => $appointment->id,
            'appointment_reference' => $appointment->appointment_reference,
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'status' => $appointment->status,
            'chief_complaint' => $record->chief_complaint ?? $appointment->chief_complaint,
            'findings' => $record->findings,
            'diagnosis' => $record->diagnosis,
            'treatment_given' => $record->treatment_given,
            'vet_notes' => $record->vet_notes,
            ...$record->clinicalDetails(),
            'follow_up_date' => $record->follow_up_date?->toDateString(),
            'follow_up_notes' => $record->follow_up_notes,
            'vitals' => $vitals ? [
                'weight_kg' => $vitals->weight_kg,
                'temperature_c' => $vitals->temperature_c,
                'heart_rate_bpm' => $vitals->heart_rate_bpm,
                'respiratory_rate_bpm' => $vitals->respiratory_rate_bpm,
                'body_condition_score' => $vitals->body_condition_score,
            ] : null,
            'attachments' => $record->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_type' => $attachment->file_type,
                'file_size_bytes' => $attachment->file_size_bytes,
                'label' => $attachment->label,
                'uploaded_at' => $attachment->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    private function formatVaccinationRecord(VaccinationRecord $record): array
    {
        return [
            'vaccine_name' => $record->vaccine_name,
            'product_name' => $record->product_name,
            'manufacturer' => $record->manufacturer,
            'batch_number' => $record->batch_number,
            'administered_date' => $record->administered_date?->toDateString(),
            'next_due_date' => $record->next_due_date?->toDateString(),
            'product_expiry_date' => $record->product_expiry_date?->toDateString(),
            'dose_amount' => $record->dose_amount,
            'dose_unit' => $record->dose_unit,
            'route' => $record->route,
            'administration_site' => $record->administration_site,
            'appointment_reference' => $record->clinicAppointment?->appointment_reference,
            'due_status' => $record->dueStatus(),
        ];
    }

}
