<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicCharge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClinicBillingService
{
    public function updateCharge(int $appointmentId, int $chargeId, array $data, User $actor): ClinicCharge
    {
        abort_unless($actor->clinicPermissions()['billing_edit'], 403);
        $data = Validator::make($data, [
            'quantity' => 'sometimes|required|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'unit_price' => 'sometimes|required|numeric|decimal:0,2|min:0|max:99999999.99',
        ])->validate();
        return DB::transaction(function () use ($appointmentId, $chargeId, $data) {
            $appointment = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            \App\Models\GatewayPayment::assertClinicUnlocked($appointment);
            if ($appointment->paid || $appointment->archived_at
                || ! in_array($appointment->status, ['checked_in', 'in_consultation', 'for_payment'], true)) {
                throw ValidationException::withMessages(['charges' => 'Billing cannot be changed at this case stage.']);
            }
            $charge = $appointment->charges()->whereKey($chargeId)->firstOrFail();
            if ($charge->clinic_case_service_id !== null || $charge->vaccination_record_id !== null) {
                throw ValidationException::withMessages(['charges' => 'Source-generated clinical charges are read-only. Their quantity and price come from the finalized clinical action.']);
            }
            $charge->fill($data);
            $charge->amount = $this->lineAmount($charge->quantity, $charge->unit_price);
            $charge->save();
            $this->updateTotal($appointment);
            return $charge;
        });
    }

    public function lineAmount(mixed $quantity, mixed $price): string
    {
        $amount = round((float) $quantity * (float) $price, 2);
        if ($amount > 99999999.99) {
            throw ValidationException::withMessages(['charges' => 'The charge exceeds the supported total.']);
        }
        return number_format($amount, 2, '.', '');
    }

    // Call inside a transaction with the case locked.
    public function updateTotal(ClinicAppointment $appointment): void
    {
        $total = (float) $appointment->charges()->sum('amount');
        if ($total > 99999999.99) {
            throw ValidationException::withMessages(['charges' => 'The case charges exceed the supported total.']);
        }
        $appointment->update(['total_amount' => $total]);
    }
}
