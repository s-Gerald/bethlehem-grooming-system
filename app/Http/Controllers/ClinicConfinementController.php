<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\ClinicService;
use App\Services\ClinicConfinementService;
use Illuminate\Http\Request;

class ClinicConfinementController extends Controller
{
    public function show(int $id)
    {
        $case = ClinicAppointment::findOrFail($id);
        $episode = $case->confinement()->with(ClinicConfinementService::RELATIONS)->first();
        $pricing = $episode && ! $episode->discharged_at
            ? app(\App\Services\ClinicServicePriceResolver::class)->confinementPreview($episode->caseService) : null;
        if ($episode) foreach ($episode->confinementCharges as $extra) {
            if (! $extra->removed_at && $extra->caseService->status === 'ordered') {
                $extra->setAttribute('pricing', app(\App\Services\ClinicServicePriceResolver::class)->preview($extra->caseService->clinicService, $case));
            }
        }
        return response()->json(['data' => $episode, 'pricing' => $pricing]);
    }

    public function options(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1']);
        return response()->json(ClinicService::with('category')->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('name', ClinicConfinementService::CATEGORY))
            ->where('charge_behavior', 'additional_charge')
            ->where(fn ($q) => $q->whereNull('billing_unit')->orWhereIn('billing_unit', ['service', 'day']))
            ->when(! empty($data['q']), fn ($q) => $q->where('name', 'like', '%'.$data['q'].'%'))
            ->orderBy('name')->orderBy('id')->paginate(10));
    }

    public function admit(Request $request, int $id, ClinicConfinementService $service)
    {
        $episode = $service->admit($id, $request->all(), $request->user());
        return response()->json(['data' => $episode], $episode->wasRecentlyCreated ? 201 : 200);
    }

    public function admissionPreview(Request $request, int $id, ClinicConfinementService $service)
    {
        $data = $request->validate(['weight_kg' => ['nullable', new \App\Rules\ValidPetWeight(requireSpecies: false, clinical: true)]]);
        $case = ClinicAppointment::with(['vitals', 'pet'])->findOrFail($id);
        if (array_key_exists('weight_kg', $data)) {
            $vitals = $case->vitals ? clone $case->vitals : new \App\Models\ClinicVital;
            $vitals->weight_kg = $data['weight_kg'];
            $case->setRelation('vitals', $vitals);
        }
        return response()->json($service->admissionPreview($case));
    }

    public function addCharge(Request $request, int $id, ClinicConfinementService $service)
    {
        $extra = $service->addCharge($id, $request->all(), $request->user());
        return response()->json(['data' => $extra], $extra->wasRecentlyCreated ? 201 : 200);
    }

    public function stopCharge(Request $request, int $id, int $chargeId, ClinicConfinementService $service)
    {
        return response()->json(['data' => $service->stopCharge($id, $chargeId, $request->all(), $request->user())]);
    }

    public function removeCharge(Request $request, int $id, int $chargeId, ClinicConfinementService $service)
    {
        $service->removeCharge($id, $chargeId, $request->user());
        return response()->json(['message' => 'Additional charge removed.']);
    }

    public function observations(int $id)
    {
        $episode = ClinicAppointment::findOrFail($id)->confinement()->firstOrFail();
        return response()->json($episode->observations()->orderBy('recorded_at')->orderBy('id')->paginate(20));
    }

    public function recordObservation(Request $request, int $id, ClinicConfinementService $service)
    {
        $entry = $service->recordObservation($id, $request->all(), $request->user());
        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function preview(int $id, ClinicConfinementService $service)
    {
        return response()->json($service->dischargePreview(ClinicAppointment::with(['vitals', 'pet'])->findOrFail($id)));
    }

    public function discharge(Request $request, int $id, ClinicConfinementService $service)
    {
        return response()->json(['data' => $service->discharge($id, $request->all(), $request->user())]);
    }
}
