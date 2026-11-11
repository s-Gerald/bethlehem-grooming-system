<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Services\ClinicBillingService;
use App\Services\ClinicPaymentSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicBillingController extends Controller
{
    public function index(Request $request, int $id, ClinicPaymentSettlementService $settlement): JsonResponse
    {
        $request->validate(['scope' => 'nullable|in:treatment']);
        $appointment = ClinicAppointment::findOrFail($id);
        $charges = $appointment->charges();
        if ($request->input('scope') === 'treatment') $charges->whereIn('kind', ['medicine', 'vaccine']);
        $group = ! $request->filled('scope') && $appointment->clinic_visit_group_id ? $appointment->visitGroup : null;
        return response()->json([
            'total_amount' => $appointment->total_amount, 'paid' => $appointment->paid,
            'charges' => $charges->with('caseService')->orderBy('id')->paginate(20),
            'session' => $group ? $settlement->breakdown($group, $settlement->patients($group)) : null,
        ]);
    }

    public function update(Request $request, int $id, int $chargeId, ClinicBillingService $billing): JsonResponse
    {
        return response()->json(['data' => $billing->updateCharge($id, $chargeId, $request->all(), $request->user())]);
    }
}
