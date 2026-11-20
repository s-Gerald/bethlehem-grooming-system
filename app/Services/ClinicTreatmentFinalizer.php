<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\GatewayPayment;
use App\Models\InventoryItem;
use App\Models\User;
use App\Models\VaccinationRecord;
use App\Support\ClinicClinicalRules;
use Illuminate\Support\Collection;
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
        $this->assertPublishable($record);
        $usesInventory = $record->record_source === 'inventory';

        $item = null;
        if ($usesInventory) {
            $item = InventoryItem::query()->whereKey($record->inventory_item_id)->with('weightPrices')->lockForUpdate()->first();
            if (! $item || ! $item->is_active || $item->category !== $record->treatment_type) {
                throw ValidationException::withMessages(['inventory_item_id' => 'Select an active Inventory product of the correct treatment type.']);
            }
            if (InventoryItem::isMeasuredUnit($item->unit)) {
                throw ValidationException::withMessages(['inventory_item_id' => 'Review this legacy measured unit in Inventory before using the product as physical Treatment stock. Existing quantities are not converted.']);
            }
            $balance = $this->batchBalances->forItem((int) $item->item_id);
            $availability = $this->batchBalances->treatmentAvailability($item, $balance);
            if (($availability['next_batch'] || $balance['unknown_expiry_quantity'] > 0)
                && ClinicClinicalRules::productExpiryErrors($availability['next_batch']['expiry_date'] ?? null, $record->administered_date?->toDateString(), true)) {
                throw ValidationException::withMessages(['inventory_item_id' => ClinicClinicalRules::INVENTORY_EXPIRY_MESSAGE]);
            }
            if ((float) $record->inventory_quantity > $availability['available_quantity']) {
                throw ValidationException::withMessages(['inventory_quantity' => 'Quantity used exceeds available unexpired stock.']);
            }
            $record->forceFill([
                'batch_number' => $availability['next_batch']['batch_number'],
                'product_expiry_date' => $availability['next_batch']['expiry_date'],
            ]);
        }

        $this->publishRecord($record, $case, $actor, $item);
    }

    private function assertPublishable(VaccinationRecord $record): void
    {
        $errors = app(ClinicTreatmentChargeService::class)->publishingErrors($record);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function publishRecord(VaccinationRecord $record, ?ClinicAppointment $case, User $actor, ?InventoryItem $item): void
    {
        if ($case) {
            // An existing charge must never bypass current price/weight review or physical usage.
            app(ClinicTreatmentChargeService::class)->sync($record, $case, $item);
        }
        if ($item) {
            $this->stockMovements->remove($actor, [[
                'item_id' => $item->item_id,
                'quantity' => $record->inventory_quantity,
                'unit_cost_at_time' => $item->unit_cost,
                'selling_price' => $record->expected_price,
                'reason' => 'used',
                'batch_number' => $record->batch_number,
                'expiry_date' => $record->product_expiry_date?->toDateString(),
                'notes' => ($record->treatment_type === 'medicine' ? 'Medicine/treatment' : 'Vaccination').' · '.$record->pet->pet_name,
                'reference_type' => 'clinic',
                'reference_id' => $record->clinic_appointment_id,
                'vaccination_record_id' => $record->id,
            ]]);
        }

        $record->forceFill([
            'published_at' => now(),
            'published_by_user_id' => $actor->user_id,
        ])->save();
    }

    public function price(InventoryItem $item, ClinicAppointment $case): array
    {
        return app(ClinicTreatmentChargeService::class)->price($item, $case);
    }

    /** The group, selected cases and shared inventory item are locked by the caller. */
    public function publishBatch(InventoryItem $item, Collection $records, Collection $cases, User $actor, ClinicGroupClinicalPatients $scope): void
    {
        if ($records->isEmpty()) return;

        $balance = $this->batchBalances->forItem($item->item_id);
        $reservation = GatewayPayment::reservedQuantities([$item->item_id])[$item->item_id] ?? 0;
        $availability = $this->batchBalances->treatmentAvailability($item, $balance);
        $available = $availability['available_quantity'];
        $available = min($available, $this->batchBalances->quantities($item, $balance, (float) $reservation)['saleable_quantity']);
        $medicine = app(ClinicMedicineChargeService::class);
        $prepared = $records->map(function (VaccinationRecord $record, int $id) use ($cases, $item, $scope, $availability, &$available) {
            $case = $cases->get($id);
            try {
                $record->forceFill([
                    'batch_number' => $availability['next_batch']['batch_number'] ?? null,
                    'product_expiry_date' => $availability['next_batch']['expiry_date'] ?? null,
                ]);
                $this->assertPublishable($record);
                if ((float) $record->inventory_quantity > $available) {
                    throw ValidationException::withMessages(['inventory_quantity' => 'The selected patients need more unexpired stock than is available.']);
                }
                $available -= (float) $record->inventory_quantity;
                $pricing = app(ClinicTreatmentChargeService::class)->reviewPrice($record, $item, $case);
                $total = round((float) $case->charges_sum_amount + (float) $pricing['price'], 2);
                if ($total > 99999999.99) {
                    throw ValidationException::withMessages(['charges' => 'The case charges exceed the supported total.']);
                }
                return [$case, $pricing, $total];
            } catch (ValidationException $error) {
                $scope->fail($case, $error);
            }
        });

        $movements = $records->map(function (VaccinationRecord $record, int $id) use ($item, $prepared) {
            $record->save();
            [$case, $pricing] = $prepared[$id];
            return [
                'item_id' => $item->item_id, 'quantity' => $record->inventory_quantity, 'reason' => 'used',
                'unit_cost_at_time' => $item->unit_cost, 'selling_price' => $pricing['price'],
                'reference_type' => 'clinic', 'reference_id' => $case->id, 'vaccination_record_id' => $record->id,
                'notes' => ($item->category === 'vaccine' ? 'Vaccination' : 'Medicine/treatment').' · '.$case->pet->pet_name,
                'capture_batch' => true,
            ];
        });
        $batches = $this->stockMovements->remove($actor, $movements);

        foreach ($records->keys()->values() as $index => $id) {
            $record = $records[$id];
            [$case, $pricing, $total] = $prepared[$id];
            $record->update([
                'batch_number' => $batches[$index]['batch_number'],
                'product_expiry_date' => $batches[$index]['expiry_date'],
                'published_at' => now(), 'published_by_user_id' => $actor->user_id,
            ]);
            if ($item->category === 'medicine') {
                $medicine->recordCharge($case, $item, $pricing, $record->request_key, $record->id);
            } else {
                app(ClinicTreatmentChargeService::class)->recordCharge($case, $record, $pricing['price'], $item->item_id);
            }
            $case->update(['total_amount' => $total]);
        }
    }
}
