<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicConfinement;
use App\Models\Pet;
use App\Models\VaccinationRecord;
use App\Services\PetProfileHistoryService;
use Illuminate\Http\Request;

class AdminPetProfileController extends Controller
{
    public function caseDetails(Request $request, int $petId, int $caseId, PetProfileHistoryService $history, AdminVaccinationController $treatments)
    {
        $request->validate(['view' => 'sometimes|in:history']);
        $historyView = $request->input('view') === 'history';
        $case = ($historyView ? $history->eligibleClinicalCases($petId) : ClinicAppointment::query()->where('pet_id', $petId))->with([
            'record.attachments', 'vitals',
            'vaccinationRecords' => fn ($query) => $query->where('pet_id', $petId)
                ->when($historyView, fn ($query) => $query->published()->notVoided())
                ->with(AdminVaccinationController::RESPONSE_RELATIONS)->orderByDesc('administered_date')->orderByDesc('id'),
            'caseServices' => fn ($query) => $query->where('status', 'performed')->with('clinicService')
                ->orderByDesc('performed_at')->orderByDesc('id'),
            'confinement.caseService.clinicService',
        ])->findOrFail($caseId);

        return response()->json([
            'case' => [...$case->only(['id', 'appointment_reference', 'status']), 'appointment_date' => $case->appointment_date?->toDateString()],
            'medical_record' => $case->record ? $history->formatMedicalRecord($case) : null,
            'treatments' => $case->vaccinationRecords->map(fn ($record) => $treatments->formatRecord($record))->values(),
            'services' => $case->caseServices,
            'confinement' => $case->confinement,
        ]);
    }

    public function show(
        Request $request,
        int $petId,
        PetProfileHistoryService $history,
        AdminVaccinationController $treatments,
    ) {
        if (! in_array($request->user()?->role, ['admin', 'staff'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin or staff access required.',
            ], 403);
        }

        $pet = Pet::query()->find($petId);

        if (! $pet) {
            return response()->json([
                'success' => false,
                'message' => 'Pet not found.',
            ], 404);
        }

        $data = $request->validate([
            'history' => 'sometimes|in:services,confinement,grooming,clinical',
            'view' => 'sometimes|in:history',
            'page' => 'sometimes|integer|min:1',
        ]);
        if (($data['history'] ?? null) === 'clinical' || ($data['view'] ?? null) === 'history') {
            abort_unless($request->user()->clinicPermissions()['access'] ?? false, 403);
        }
        if (($data['history'] ?? null) === 'grooming') {
            return response()->json(['success' => true, 'grooming_records' => $history->groomingRecords($pet)]);
        }
        if (($data['history'] ?? null) === 'clinical') {
            $records = $history->clinicalHistory($pet);
            $historical = VaccinationRecord::query()
                ->whereIn('id', $records->getCollection()->where('record_type', 'historical')->pluck('id'))
                ->with(AdminVaccinationController::RESPONSE_RELATIONS)->get()->keyBy('id');
            $records->through(function ($record) use ($historical, $treatments) {
                return [...(array) $record, ...($record->record_type === 'historical'
                    ? ['treatment' => $treatments->formatRecord($historical[$record->id])] : [])];
            });
            return response()->json($records);
        }
        if (($data['history'] ?? null) === 'services') {
            // Round each two-decimal quantity/price pair in cents before summing;
            // SQLite's binary product can otherwise round a half cent down.
            return response()->json(ClinicAppointment::query()
                ->join('clinic_case_services as services', 'services.clinic_appointment_id', '=', 'clinic_appointments.id')
                ->where('clinic_appointments.pet_id', $petId)->where('services.status', 'performed')
                ->selectRaw('clinic_appointments.id as appointment_id, clinic_appointments.appointment_reference,
                    COUNT(*) as service_count,
                    SUM(ROUND(ROUND(services.quantity * 100, 0) * ROUND(services.applied_price * 100, 0) / 100, 0)) / 100 as service_total')
                ->groupBy('clinic_appointments.id', 'clinic_appointments.appointment_reference')
                ->orderByRaw('MAX(services.performed_at) DESC')->orderByDesc('clinic_appointments.id')
                ->withCasts(['appointment_id' => 'integer', 'service_count' => 'integer', 'service_total' => 'decimal:2'])
                ->paginate(20));
        }
        if (($data['history'] ?? null) === 'confinement') {
            return response()->json(ClinicConfinement::query()
                ->whereHas('appointment', fn ($query) => $query->where('pet_id', $petId))
                ->with(['appointment:id,appointment_reference,appointment_date,status', 'caseService.clinicService', 'caseService.charge'])
                ->orderByDesc('admitted_at')->orderByDesc('id')->paginate(20));
        }

        return response()->json([
            'success' => true,
            'grooming_records' => $history->groomingRecords($pet),
            'medical_records' => $history->medicalRecords($pet),
            'vaccinations' => $history->vaccinationRecords($pet),
        ]);
    }
}
