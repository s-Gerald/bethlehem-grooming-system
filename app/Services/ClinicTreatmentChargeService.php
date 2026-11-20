<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\InventoryItem;
use App\Models\VaccinationRecord;
use App\Support\ClinicClinicalRules;
use Illuminate\Validation\ValidationException;

class ClinicTreatmentChargeService
{
    public function publishingErrors(VaccinationRecord $record): array
    {
        $current = $record->record_source !== 'historical';
        $errors = ClinicClinicalRules::doseErrors($record->dose_amount, $record->dose_unit, $current)
            + ClinicClinicalRules::administrationErrors($record->route, $record->administration_site, $current);
        if ($current) {
            $expiryErrors = ClinicClinicalRules::productExpiryErrors($record->product_expiry_date?->toDateString(), $record->administered_date?->toDateString(), true);
            $errors += $record->record_source === 'inventory' && $expiryErrors
                ? ['inventory_item_id' => ClinicClinicalRules::INVENTORY_EXPIRY_MESSAGE] : $expiryErrors;
        }
        if ($record->voided_at !== null || ! $record->hasPublishingRequirements()) {
            $errors['treatment'] = 'Complete the treatment name and administration date.';
        }
        if ($current && $record->administered_date?->toDateString() !== now()->toDateString()) {
            $errors['administered_date'] = 'Review this treatment’s administration date before finalizing.';
        }
        if ($record->record_source === 'manual') {
            if (! VaccinationRecord::validManualPrice($record->manual_price)) {
                $errors['manual_price'] = VaccinationRecord::MANUAL_PRICE_MESSAGE;
            }
            if (! $record->clinic_appointment_id) $errors['clinic_appointment_id'] = 'Select an ongoing Clinic case.';
        }
        if ($record->record_source === 'inventory') {
            if (! $record->inventory_item_id) $errors['inventory_item_id'] = 'Select an Inventory product.';
            if (! is_numeric($record->inventory_quantity) || (float) $record->inventory_quantity < 1
                || (float) $record->inventory_quantity > 99999999
                || floor((float) $record->inventory_quantity) !== (float) $record->inventory_quantity) {
                $errors['inventory_quantity'] = 'Enter a whole number of physical inventory units, at least 1.';
            }
            if ($record->treatment_type === 'medicine' && (! $record->clinic_appointment_id || ! $record->request_key || $record->expected_price === null)) {
                $errors['medicines'] = 'Select the medicine again to review its current price.';
            }
        }
        return $errors;
    }

    public function price(InventoryItem $item, ClinicAppointment $case): array
    {
        if (InventoryItem::isMeasuredUnit($item->unit)) {
            throw ValidationException::withMessages(['inventory_item_id' => 'Select a product with physical inventory units.']);
        }
        if ($item->category === 'medicine') {
            return app(ClinicMedicineChargeService::class)->price($item, $case->vitals?->weight_kg);
        }
        if ($item->selling_price === null || (float) $item->selling_price < 0) {
            throw ValidationException::withMessages(['inventory_item_id' => 'Set this vaccine’s selling price in Inventory.']);
        }
        return ['price' => $item->selling_price, 'pet_weight_kg' => null];
    }

    public function reviewPrice(VaccinationRecord $record, InventoryItem $item, ClinicAppointment $case): array
    {
        if (InventoryItem::isMeasuredUnit($item->unit)) {
            throw ValidationException::withMessages(['inventory_item_id' => 'Select a product with physical inventory units.']);
        }
        if ($item->category === 'medicine') {
            return app(ClinicMedicineChargeService::class)->reviewPrice($item, $case->vitals?->weight_kg, $record->expected_price, $record->expected_weight_kg);
        }
        $pricing = $this->price($item, $case);
        if ($record->expected_price !== null && (float) $pricing['price'] !== (float) $record->expected_price) {
            throw ValidationException::withMessages(['expected_price' => 'The vaccine price changed. Select the vaccine again to review its current price.']);
        }
        return $pricing;
    }

    // Caller holds the case lock in the same transaction as the treatment save/publication.
    // Incomplete drafts are retained, but never billed. This method never consumes stock.
    public function sync(VaccinationRecord $record, ClinicAppointment $case, ?InventoryItem $item = null): void
    {
        if ($record->record_source === 'historical') return;
        if ($this->publishingErrors($record)) {
            $case->charges()->where('vaccination_record_id', $record->id)->delete();
        } elseif ($record->record_source === 'inventory') {
            $item ??= InventoryItem::whereKey($record->inventory_item_id)->with('weightPrices')->lockForUpdate()->first();
            if (! $item || ! $item->is_active || $item->category !== $record->treatment_type) {
                throw ValidationException::withMessages(['inventory_item_id' => 'Select an active Inventory product of the correct treatment type.']);
            }
            $pricing = $this->reviewPrice($record, $item, $case);
            if ($item->category === 'medicine') {
                app(ClinicMedicineChargeService::class)->recordCharge($case, $item, $pricing, $record->request_key, $record->id);
            } else {
                $record->forceFill(['expected_price' => $pricing['price']])->save();
                $this->recordCharge($case, $record, $pricing['price'], $item->item_id);
            }
        } else {
            $this->recordCharge($case, $record, $record->manual_price, null);
        }
        app(ClinicBillingService::class)->updateTotal($case);
    }

    public function recordCharge(ClinicAppointment $case, VaccinationRecord $record, mixed $price, ?int $itemId): void
    {
        $charge = $case->charges()->firstOrNew(['vaccination_record_id' => $record->id]);
        $charge->fill([
            'kind' => $record->treatment_type, 'description' => $record->vaccine_name,
            'inventory_item_id' => $itemId, 'quantity' => 1, 'unit_price' => $price, 'amount' => $price,
        ])->forceFill(['request_key' => null, 'pet_weight_kg' => null, 'min_weight_kg' => null, 'max_weight_kg' => null])->save();
    }
}
