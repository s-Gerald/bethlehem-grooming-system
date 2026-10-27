<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\InventoryItem;
use App\Models\User;
use App\Models\VaccinationRecord;
use Illuminate\Validation\ValidationException;

class ClinicTreatmentFinalizer
{
    public function __construct(
        private readonly InventoryBatchBalanceService $batchBalances,
        private readonly InventoryStockMovementService $stockMovements,
    ) {
    }

    // Call inside the case transaction, with the case locked before its treatments.
    public function finalizeVisit(ClinicAppointment $case, User $actor): void
    {
        $records = VaccinationRecord::where('clinic_appointment_id', $case->id)
            ->whereNull('published_at')->whereNull('voided_at')
            ->with('pet')->orderBy('id')->lockForUpdate()->get();
        foreach ($records as $record) {
            try {
                $this->commit($record, $case, $actor);
            } catch (ValidationException $error) {
                $errors = [];
                foreach ($error->errors() as $field => $messages) $errors['treatments.'.$record->id.'.'.$field] = $messages;
                throw ValidationException::withMessages($errors);
            }
        }
    }

    public function commit(VaccinationRecord $record, ?ClinicAppointment $case, User $actor): void
    {
        if ($record->published_at !== null) return;
        if ($record->voided_at !== null || ! $record->hasPublishingRequirements()) {
            throw ValidationException::withMessages(['treatment' => 'Complete the treatment name and administration date.']);
        }
        $usesInventory = $record->inventory_item_id !== null;
        if ($usesInventory && $record->administered_date->toDateString() !== now()->toDateString()) {
            throw ValidationException::withMessages(['administered_date' => 'Review this treatment’s administration date before finalizing.']);
        }
        $doseErrors = \App\Support\ClinicClinicalRules::doseErrors($record->dose_amount, $record->dose_unit, $usesInventory);
        if ($doseErrors) throw ValidationException::withMessages($doseErrors);

        $item = null;
        if ($usesInventory) {
            if (! is_numeric($record->inventory_quantity) || (float) $record->inventory_quantity < 1
                || (float) $record->inventory_quantity > 99999999
                || floor((float) $record->inventory_quantity) !== (float) $record->inventory_quantity) {
                throw ValidationException::withMessages(['inventory_quantity' => 'Enter a whole number of physical inventory units, at least 1.']);
            }
            $item = InventoryItem::query()->whereKey($record->inventory_item_id)->lockForUpdate()->first();
            if (! $item || ! $item->is_active || $item->category !== $record->treatment_type) {
                throw ValidationException::withMessages(['inventory_item_id' => 'Select an active Inventory product of the correct treatment type.']);
            }
            if (InventoryItem::isMeasuredUnit($item->unit)) {
                throw ValidationException::withMessages(['inventory_item_id' => 'Review this legacy measured unit in Inventory before using the product as physical Treatment stock. Existing quantities are not converted.']);
            }
            $balance = $this->batchBalances->forItem((int) $item->item_id);
            $availability = $this->batchBalances->treatmentAvailability($item, $balance);
            if ((float) $record->inventory_quantity > $availability['available_quantity']) {
                throw ValidationException::withMessages(['inventory_quantity' => 'Quantity used exceeds available unexpired stock.']);
            }
            $record->forceFill([
                'batch_number' => $availability['next_batch']['batch_number'],
                'product_expiry_date' => $availability['next_batch']['expiry_date'],
            ]);
        }

        if ($usesInventory && $record->treatment_type === 'medicine') {
            if (! $case || ! $record->inventory_quantity || ! $record->request_key) {
                throw ValidationException::withMessages(['inventory_quantity' => 'An ongoing Clinic case and inventory quantity used are required for medicine treatments.']);
            }
            app(ClinicMedicineChargeService::class)->save($case, [[
                'inventory_item_id' => $record->inventory_item_id,
                'inventory_quantity' => $record->inventory_quantity,
                'expected_price' => $record->expected_price,
                'expected_weight_kg' => $record->expected_weight_kg,
                'request_key' => $record->request_key,
                'vaccination_record_id' => $record->id,
                'batch_number' => $record->batch_number,
                'expiry_date' => $record->product_expiry_date?->toDateString(),
            ]], $actor);
        } elseif ($usesInventory) {
            if ($case && ($item->selling_price === null || (float) $item->selling_price < 0)) {
                throw ValidationException::withMessages(['inventory_item_id' => 'Set this vaccine\'s selling price in Inventory before finalizing its case charge.']);
            }
            $this->stockMovements->remove($actor, [[
                'item_id' => $item->item_id,
                'quantity' => $record->inventory_quantity,
                'unit_cost_at_time' => $item->unit_cost,
                'reason' => 'used',
                'batch_number' => $record->batch_number,
                'expiry_date' => $record->product_expiry_date?->toDateString(),
                'notes' => "Vaccination · {$record->pet->pet_name}",
                'reference_type' => 'clinic',
                'reference_id' => $record->clinic_appointment_id,
                'vaccination_record_id' => $record->id,
            ]]);
        }

        $record->forceFill([
            'published_at' => now(),
            'published_by_user_id' => $actor->user_id,
        ])->save();

        if ($case && $usesInventory && $record->treatment_type !== 'medicine') {
            $case->charges()->create([
                'kind' => 'vaccine', 'description' => $record->vaccine_name,
                'inventory_item_id' => $item->item_id, 'vaccination_record_id' => $record->id,
                'quantity' => 1, 'unit_price' => $item->selling_price, 'amount' => $item->selling_price,
            ]);
            app(ClinicBillingService::class)->updateTotal($case);
        }
    }
}
