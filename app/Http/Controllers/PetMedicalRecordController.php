<?php

namespace App\Http\Controllers;

use App\Support\PetProfileSnapshot;
use App\Models\ClinicAppointment;
use App\Models\GatewayPayment;
use App\Models\Pet;
use App\Models\VaccinationRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PetMedicalRecordController extends Controller
{
    public function index(Request $request, int $petId)
    {
        $pet = Pet::query()
            ->where('pet_id', $petId)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if (! $pet) {
            return response()->json([
                'success' => false,
                'message' => 'Pet not found.',
            ], 404);
        }

        $medicalRecords = $this->completedRecords()
            ->where('pet_id', $pet->pet_id)
            ->get()
            ->map(fn (ClinicAppointment $appointment) => $this->formatMedicalRecord($appointment))
            ->values();

        return response()->json([
            'success' => true,
            'medical_records' => $medicalRecords,
        ]);
    }

    public function history(Request $request)
    {
        $records = $this->completedRecords()
            ->where(fn (Builder $query) => $query
                ->whereHas('pet', fn (Builder $pet) => $pet->where('user_id', $request->user()->user_id))
                ->orWhere(fn (Builder $missingPet) => $missingPet->whereDoesntHave('pet')
                    ->where('user_id', $request->user()->user_id)))
            ->get()
            ->map(fn (ClinicAppointment $appointment) => [
                ...$this->formatMedicalRecord($appointment),
                'pet' => [
                    'pet_id' => $appointment->pet_id,
                    'pet_name' => PetProfileSnapshot::resolve($appointment->pet_profile_snapshot)['pet_name'] ?? null,
                    'species' => PetProfileSnapshot::resolve($appointment->pet_profile_snapshot)['species'] ?? null,
                ],
            ])
            ->values();

        return response()->json(['success' => true, 'medical_records' => $records]);
    }

    private function completedRecords(): Builder
    {
        return ClinicAppointment::query()
            ->select([
                'id',
                'appointment_reference',
                'appointment_date',
                'status',
                'pet_id',
                'pet_profile_snapshot',
                'chief_complaint',
                'paid',
                'total_amount',
                'payment_method',
                'paid_at',
            ])
            ->where('status', 'completed')
            ->whereHas('record')
            ->with([
                'vitals:id,clinic_appointment_id,weight_kg,temperature_c,heart_rate_bpm,respiratory_rate_bpm,body_condition_score',
                'record:id,clinic_appointment_id,chief_complaint,diagnosis,working_diagnosis,primary_veterinarian_name,treatment_given,follow_up_date,follow_up_notes,prescriptions',
                'vaccinationRecords' => fn ($query) => $query
                    ->select('clinic_appointment_id', 'treatment_type', 'vaccine_name', 'administered_date', 'next_due_date', 'dose_amount', 'dose_unit', 'route')
                    ->whereHas('clinicAppointment', fn (Builder $appointment) => $appointment
                        ->whereColumn('clinic_appointments.pet_id', 'vaccination_records.pet_id'))
                    ->where(fn (Builder $treatments) => $treatments->visibleToClients()
                        ->orWhere(fn (Builder $medicines) => $medicines->where('treatment_type', 'medicine')->published()->notVoided()))
                    ->orderByDesc('administered_date')
                    ->orderBy('id'),
            ])
            ->orderByDesc('appointment_date')
            ->orderByDesc('id');
    }

    private function formatMedicalRecord(ClinicAppointment $appointment): array
    {
        $record = $appointment->record;
        $vitals = $appointment->vitals;
        $diagnosis = $record->working_diagnosis ?? $record->diagnosis;
        [$vaccinations, $medicines] = $appointment->vaccinationRecords->partition('treatment_type', 'vaccine');

        return [
            'appointment_reference' => $appointment->appointment_reference,
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'status' => $appointment->status,
            'payment' => $appointment->paid && $appointment->total_amount !== null
                && is_numeric($appointment->total_amount) && (float) $appointment->total_amount >= 0
                && filled($appointment->payment_method) && $appointment->paid_at
                ? [
                    'status' => 'paid',
                    'total_paid' => (float) $appointment->total_amount,
                    'payment_method' => $appointment->payment_method,
                    'payment_method_label' => GatewayPayment::labelFor($appointment->payment_method),
                    'paid_at' => $appointment->paid_at->toIso8601String(),
                ] : null,
            'chief_complaint' => $record->chief_complaint ?? $appointment->chief_complaint,
            'diagnosis' => $diagnosis,
            'diagnosis_source' => filled($diagnosis) ? ($record->working_diagnosis !== null ? 'working_diagnosis' : 'diagnosis') : null,
            'primary_veterinarian_name' => $record->primary_veterinarian_name,
            'treatment_given' => $record->treatment_given,
            'follow_up_date' => $record->follow_up_date?->toDateString(),
            'follow_up_notes' => $record->follow_up_notes,
            'prescriptions' => collect(is_array($record->prescriptions) ? $record->prescriptions : [])
                ->filter(fn ($prescription) => is_array($prescription) && $prescription !== [])
                ->map(fn (array $prescription) => \Illuminate\Support\Arr::only($prescription, [
                    'medicine', 'strength_concentration', 'dose', 'route', 'frequency', 'duration', 'instructions',
                ]))->values()->all(),
            'vaccinations' => $vaccinations->map(fn (VaccinationRecord $vaccine) => [
                'vaccine_name' => $vaccine->vaccine_name,
                'administered_date' => $vaccine->administered_date?->toDateString(),
                'next_due_date' => $vaccine->next_due_date?->toDateString(),
            ])->values()->all(),
            'administered_medicines' => $medicines->map(fn (VaccinationRecord $medicine) => [
                'medicine_name' => $medicine->vaccine_name,
                'dose_amount' => $medicine->dose_amount,
                'dose_unit' => $medicine->dose_unit,
                'route' => $medicine->route,
            ])->values()->all(),
            'vitals' => $vitals ? [
                'weight_kg' => $vitals->weight_kg,
                'temperature_c' => $vitals->temperature_c,
                'heart_rate_bpm' => $vitals->heart_rate_bpm,
                'respiratory_rate_bpm' => $vitals->respiratory_rate_bpm,
                'body_condition_score' => $vitals->body_condition_score,
            ] : null,
        ];
    }
}
