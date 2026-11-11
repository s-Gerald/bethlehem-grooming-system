<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicService;
use App\Models\ClinicVisitGroup;
use App\Services\ClinicCaseServiceRecorder;
use App\Services\ClinicConfinementService;
use App\Services\ClinicGroupClinicalPatients;
use App\Services\ClinicServicePriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ClinicCaseServiceController extends Controller
{
    public function bulkPricing(Request $request, int $id, int $clinicServiceId, ClinicGroupClinicalPatients $scope, ClinicServicePriceResolver $resolver): JsonResponse
    {
        $group = $scope->group($id, $request->user());
        $service = ClinicService::with('category')->where('is_active', true)->findOrFail($clinicServiceId);
        abort_if($service->category?->name === ClinicConfinementService::CATEGORY, 422, 'Manage confinement services in Confinement.');

        return response()->json(['patients' => $scope->preview($group, $request->user())->map(fn ($patient) => [
            'id' => $patient->id,
            'name' => $patient->pet->pet_name,
            'reference' => $patient->appointment_reference,
            'pricing' => $resolver->preview($service, $patient),
        ])]);
    }

    public function bulkStore(Request $request, int $id, ClinicCaseServiceRecorder $recorder, ClinicGroupClinicalPatients $scope): JsonResponse
    {
        $data = $request->validate([
            'clinic_service_id' => ['required', 'integer'],
            'patients' => ['required', 'array', 'list', 'min:1', 'max:'.ClinicVisitGroup::MAX_PATIENTS],
            'patients.*' => ['array:id,request_key,quantity,applied_price,expected_price'],
            'patients.*.id' => ['required', 'integer', 'distinct'],
            'patients.*.request_key' => ['required', 'uuid', 'distinct'],
            'patients.*.expected_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ClinicService::MAX_PRICE],
            'patients.*.applied_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ClinicService::MAX_PRICE],
            'patients.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:99999999'],
        ]);

        return response()->json(['data' => $recorder->bulk($id, $data, $request->user(), $scope)]);
    }

    public function pricing(int $id, int $clinicServiceId, ClinicServicePriceResolver $resolver): JsonResponse
    {
        $appointment = ClinicAppointment::with(['pet', 'vitals'])->findOrFail($id);
        $service = ClinicService::with('category')->where('is_active', true)->findOrFail($clinicServiceId);
        return response()->json(['data' => $service, 'pricing' => $resolver->preview($service, $appointment)]);
    }

    public function index(Request $request, int $id): JsonResponse
    {
        $request->validate(['scope' => 'nullable|in:imaging']);
        $appointment = ClinicAppointment::findOrFail($id);
        $services = $appointment->caseServices()->where('status', 'performed')->with(['clinicService', 'charge']);
        if ($request->input('scope') === 'imaging') {
            $services->imaging();
        }
        if (Schema::hasTable('clinic_confinements')) $services->withExists('confinement');
        if (Schema::hasTable('clinic_confinement_charges')) $services->withExists('confinementCharge');
        return response()->json($services->orderBy('id')->paginate(20));
    }

    public function store(Request $request, int $id, ClinicCaseServiceRecorder $recorder): JsonResponse
    {
        $request->validate(['status' => 'prohibited', 'clinic_service_id' => 'required|integer']);
        $service = ClinicService::findOrFail($request->input('clinic_service_id'));
        $request->validate(['quantity' => $service->billing_unit === 'shot'
            ? 'required|integer|min:1|max:99999999' : 'prohibited']);
        // Ordinary case services are immediately billable; ordered is reserved for workflows such as Confinement.
        $entry = $recorder->save($id, [
            ...$request->only(['clinic_service_id', 'request_key', 'notes', 'applied_price']),
            'status' => 'performed', 'quantity' => $service->billing_unit === 'shot' ? $request->integer('quantity') : 1,
        ], $request->user());
        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $id, int $caseServiceId, ClinicCaseServiceRecorder $recorder): JsonResponse
    {
        $recorder->remove($id, $caseServiceId, $request->user());
        return response()->json(['message' => 'Service removed.']);
    }
}
