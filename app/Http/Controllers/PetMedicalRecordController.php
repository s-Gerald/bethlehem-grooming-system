<?php

namespace App\Http\Controllers;

use App\Support\PetProfileSnapshot;
use App\Models\ClinicAppointment;
use App\Models\GatewayPayment;
use App\Models\Pet;
use App\Models\VaccinationRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $userId = (int) $request->user()->user_id;
        if ($request->boolean('summary')) {
            return $this->historySummary($request, $userId);
        }
        $records = $this->ownedHistory($this->completedRecords(), $userId)
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

    private function historySummary(Request $request, int $userId)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150', 'date' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1',
        ]);
        $grouped = Schema::hasTable('clinic_visit_groups');
        $base = $this->ownedHistory(ClinicAppointment::query()->where('status', 'completed'), $userId)
            ->where(fn (Builder $query) => $query->whereHas('record')->when($grouped,
                fn ($query) => $query->orWhereHas('visitGroup', fn ($group) => $group->where('user_id', $userId))));
        $search = trim($filters['search'] ?? '');
        $base->when($filters['date'] ?? null, fn ($query, $date) => $query->where('appointment_date', $date));
        if ($search !== '') {
            $base->where(function (Builder $query) use ($search) {
                $query->whereRaw('LOWER(appointment_reference) LIKE ?', ['%'.mb_strtolower($search).'%'])
                    ->orWhere(fn ($pet) => PetProfileSnapshot::whereIdentity($pet, $search, ['pet_name']))
                    ->orWhereHas('pet', fn ($pet) => $pet->whereRaw('LOWER(pet_name) LIKE ?', ['%'.mb_strtolower($search).'%']));
            });
        }
        if ($grouped) {
            $groupKey = 'CASE WHEN owned_session.id IS NOT NULL THEN clinic_appointments.clinic_visit_group_id ELSE NULL END';
            $representatives = (clone $base)->leftJoin('clinic_visit_groups as owned_session', function ($join) use ($userId) {
                $join->on('owned_session.id', '=', 'clinic_appointments.clinic_visit_group_id')->where('owned_session.user_id', $userId);
            })->select(DB::raw('MAX(clinic_appointments.id)'))
                ->groupByRaw($groupKey.', CASE WHEN owned_session.id IS NULL THEN clinic_appointments.id ELSE NULL END');
            $base->whereIn('clinic_appointments.id', $representatives)->with('visitGroup:id,user_id');
        }
        $page = $base->with('pet:pet_id,pet_name,species')->orderByDesc('appointment_date')->orderByDesc('id')->paginate(30);
        $groups = $page->getCollection()->filter(fn ($visit) => (int) $visit->visitGroup?->user_id === $userId)
            ->pluck('clinic_visit_group_id')->unique();
        $siblings = $groups->isEmpty() ? collect() : $this->ownedHistory(ClinicAppointment::query()
            ->whereIn('clinic_visit_group_id', $groups)->whereIn('status', ['completed', 'cancelled']), $userId)
            ->with('pet:pet_id,pet_name,species')->orderBy('id')->get()->groupBy('clinic_visit_group_id');

        return response()->json([
            'visits' => $page->getCollection()->map(function (ClinicAppointment $visit) use ($siblings, $userId) {
                $patients = ((int) $visit->visitGroup?->user_id === $userId ? $siblings->get($visit->clinic_visit_group_id) : null) ?? collect([$visit]);
                return [
                    'id' => $visit->id, 'appointment_date' => $visit->appointment_date?->toDateString(),
                    'appointment_reference' => $visit->appointment_reference,
                    'patients' => $patients->map(fn ($patient) => $this->historyPatient($patient))->values(),
                ];
            }),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function historyVisit(Request $request, int $id)
    {
        $userId = (int) $request->user()->user_id;
        $hasGroups = Schema::hasTable('clinic_visit_groups');
        $records = $this->ownedHistory($this->completedRecords(requireRecord: ! $hasGroups), $userId)
            ->when($hasGroups, fn ($query) => $query->addSelect('clinic_visit_group_id'));
        $visit = (clone $records)->whereKey($id)->firstOrFail();
        $groupId = $visit->clinic_visit_group_id;
        $group = $groupId ? \App\Models\ClinicVisitGroup::where('user_id', $userId)->find($groupId) : null;
        abort_unless($group || $visit->record, 404);
        $patients = $group ? $records->where('clinic_visit_group_id', $group->id)->reorder('id')->get()
            : new \Illuminate\Database\Eloquent\Collection([$visit]);
        $patients->load('pet:pet_id,pet_name,species');
        $details = $patients->map(fn ($patient) => $this->historyPatient($patient) + $this->formatMedicalRecord($patient))->toBase();
        if ($group) {
            $cancelled = $this->ownedHistory($group->appointments()->getQuery()->where('status', 'cancelled'), $userId)
                ->with('pet:pet_id,pet_name,species')->orderBy('id')->get();
            $details = $details->concat($cancelled->map(fn ($patient) => $this->historyPatient($patient)));
        }

        return response()->json([
            'patients' => $details->sortBy('id')->values(),
            'payment' => $group?->paid_at ? [
                'status' => 'paid', 'total_paid' => $group->total_amount, 'payment_method' => $group->payment_method,
                'payment_method_label' => GatewayPayment::labelFor($group->payment_method), 'paid_at' => $group->paid_at->toIso8601String(),
            ] : null,
            'grouped' => $group !== null,
        ]);
    }

    private function ownedHistory(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $owned) => $owned
            ->whereHas('pet', fn (Builder $pet) => $pet->where('user_id', $userId))
            ->orWhere(fn (Builder $missingPet) => $missingPet->whereDoesntHave('pet')
                ->where('clinic_appointments.user_id', $userId)));
    }

    private function historyPatient(ClinicAppointment $visit): array
    {
        $pet = PetProfileSnapshot::pet($visit->pet_profile_snapshot, $visit->pet_id) ?? $visit->pet;
        return [
            'id' => $visit->id, 'appointment_reference' => $visit->appointment_reference, 'status' => $visit->status,
            'pet' => ['pet_id' => $visit->pet_id, 'pet_name' => $pet?->pet_name, 'species' => $pet?->species],
        ];
    }

    private function completedRecords(bool $requireRecord = true): Builder
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
            ->when($requireRecord, fn ($query) => $query->whereHas('record'))
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
        $diagnosis = $record?->working_diagnosis ?? $record?->diagnosis;
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
            'chief_complaint' => $record?->chief_complaint ?? $appointment->chief_complaint,
            'diagnosis' => $diagnosis,
            'diagnosis_source' => filled($diagnosis) ? ($record->working_diagnosis !== null ? 'working_diagnosis' : 'diagnosis') : null,
            'primary_veterinarian_name' => $record?->primary_veterinarian_name,
            'treatment_given' => $record?->treatment_given,
            'follow_up_date' => $record?->follow_up_date?->toDateString(),
            'follow_up_notes' => $record?->follow_up_notes,
            'prescriptions' => collect(is_array($record?->prescriptions) ? $record->prescriptions : [])
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
