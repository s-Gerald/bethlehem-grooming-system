<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\Pet;
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
            ->whereHas('pet', fn (Builder $query) => $query->where('user_id', $request->user()->user_id))
            ->with('pet:pet_id,pet_name,species')
            ->get()
            ->map(fn (ClinicAppointment $appointment) => [
                ...$this->formatMedicalRecord($appointment),
                'pet' => $appointment->pet->only(['pet_id', 'pet_name', 'species']),
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
                'chief_complaint',
            ])
            ->where('status', 'completed')
            ->whereHas('record')
            ->with([
                'vitals:id,clinic_appointment_id,weight_kg,temperature_c,heart_rate_bpm,respiratory_rate_bpm,body_condition_score',
                'record:id,clinic_appointment_id,chief_complaint,diagnosis,working_diagnosis,primary_veterinarian_name,treatment_given,follow_up_date,follow_up_notes,prescriptions',
            ])
            ->orderByDesc('appointment_date')
            ->orderByDesc('id');
    }

    private function formatMedicalRecord(ClinicAppointment $appointment): array
    {
        $record = $appointment->record;
        $vitals = $appointment->vitals;
        $diagnosis = $record->working_diagnosis ?? $record->diagnosis;

        return [
            'appointment_reference' => $appointment->appointment_reference,
            'appointment_date' => $appointment->appointment_date?->toDateString(),
            'status' => $appointment->status,
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
