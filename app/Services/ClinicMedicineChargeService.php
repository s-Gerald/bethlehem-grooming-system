<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicCharge;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ClinicMedicineChargeService
{
    // Call inside a transaction with the Clinic case locked.
    public function save(ClinicAppointment $appt, array $entries, User $actor): void
    {
        if (! $entries) return;
        $items = InventoryItem::query()->whereIn('item_id', array_column($entries, 'inventory_item_id'))
            ->orderBy('item_id')->lockForUpdate()->with('weightPrices')->get()->keyBy('item_id');
        $weight = $appt->vitals()->value('weight_kg');
        $existing = ClinicCharge::whereIn('request_key', array_column($entries, 'request_key'))->get()->keyBy('request_key');
        $movements = [];
        foreach ($entries as $entry) {
            if (($entry['inventory_quantity'] ?? null) !== null
                && (! is_numeric($entry['inventory_quantity']) || (float) $entry['inventory_quantity'] < 1
                    || (float) $entry['inventory_quantity'] > 99999999
                    || floor((float) $entry['inventory_quantity']) !== (float) $entry['inventory_quantity'])) {
                throw ValidationException::withMessages(['inventory_quantity' => 'Use a whole number of physical inventory units.']);
            }
            if ($charge = $existing->get($entry['request_key'])) {
                if ((int) $charge->clinic_appointment_id !== (int) $appt->id
                    || (int) $charge->inventory_item_id !== (int) $entry['inventory_item_id']
                    || $charge->vaccination_record_id !== ($entry['vaccination_record_id'] ?? null)) {
                    throw ValidationException::withMessages(['medicines' => 'This medicine request has already been used. Reload the case.']);
                }
                continue;
            }
            $item = $items->get($entry['inventory_item_id']);
            if (! $item || ! $item->is_active || $item->category !== 'medicine') {
                throw ValidationException::withMessages(['medicines' => 'Choose an active Medicine product from Inventory.']);
            }
            if (($entry['inventory_quantity'] ?? null) !== null && InventoryItem::isMeasuredUnit($item->unit)) {
                throw ValidationException::withMessages(['inventory_quantity' => 'Review this legacy measured unit in Inventory before using physical stock. Existing quantities are not converted.']);
            }
            $pricing = $this->reviewPrice($item, $weight, $entry['expected_price'], $entry['expected_weight_kg'] ?? null);
            $this->recordCharge($appt, $item, $pricing, $entry['request_key'], $entry['vaccination_record_id'] ?? null);
            // Staff specifies physical stock use independently from the price band.
            if (($entry['inventory_quantity'] ?? null) !== null) {
                $movements[] = [
                    'item_id' => $item->item_id, 'quantity' => $entry['inventory_quantity'], 'reason' => 'used',
                    'unit_cost_at_time' => $item->unit_cost, 'selling_price' => $pricing['price'],
                    'reference_type' => 'clinic', 'reference_id' => $appt->id,
                    'vaccination_record_id' => $entry['vaccination_record_id'] ?? null,
                    'batch_number' => $entry['batch_number'] ?? null, 'expiry_date' => $entry['expiry_date'] ?? null,
                    'notes' => 'Medicine/treatment · '.$item->item_name,
                ];
            }
        }
        if ($movements) app(InventoryStockMovementService::class)->remove($actor, $movements);
        app(ClinicBillingService::class)->updateTotal($appt);
    }

    public function price(InventoryItem $item, mixed $weight): array
    {
        if ($item->pricing_method === 'weight_based' && ! \App\Support\PetWeightSize::isValidWeight($weight)) {
            throw ValidationException::withMessages(['weight_kg' => "Record the pet's weight in Vitals before using this weight-based medicine."]);
        }
        return $item->clinicPrice($weight);
    }

    public function reviewPrice(InventoryItem $item, mixed $weight, mixed $expectedPrice, mixed $expectedWeight): array
    {
        $pricing = $this->price($item, $weight);
        if (round((float) $pricing['price'] * 100) !== round((float) $expectedPrice * 100)
            || ($pricing['pet_weight_kg'] !== null && (float) $pricing['pet_weight_kg'] !== (float) $expectedWeight)) {
            throw ValidationException::withMessages(['medicines' => 'The pet weight or medicine price has changed. Select the medicine again to review its current price.']);
        }
        return $pricing;
    }

    public function recordCharge(ClinicAppointment $case, InventoryItem $item, array $pricing, string $requestKey, ?int $recordId): void
    {
        $case->charges()->make([
            'kind' => 'medicine', 'description' => $item->item_name, 'inventory_item_id' => $item->item_id,
            'vaccination_record_id' => $recordId,
            'quantity' => 1, 'unit_price' => $pricing['price'], 'amount' => $pricing['price'],
        ])->forceFill([
            'request_key' => $requestKey, 'pet_weight_kg' => $pricing['pet_weight_kg'],
            'min_weight_kg' => $pricing['min_weight_kg'], 'max_weight_kg' => $pricing['max_weight_kg'],
        ])->save();
    }
}
