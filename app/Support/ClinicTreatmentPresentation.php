<?php

namespace App\Support;

use App\Models\VaccinationRecord;

/** Shared staff treatment response; callers eager-load RELATIONS. */
final class ClinicTreatmentPresentation
{
    public const RELATIONS = [
        'clinicAppointment:id,appointment_reference',
        'inventoryItem:item_id,item_name,category,unit',
        'recordedBy:user_id,first_name,last_name,role,admin_type,staff_subrole',
        'publishedBy:user_id,first_name,last_name,role,admin_type,staff_subrole',
        'voidedBy:user_id,first_name,last_name,role,admin_type,staff_subrole',
    ];

    public static function record(VaccinationRecord $record): array
    {
        return [
            'id' => $record->id,
            'treatment_type' => $record->treatment_type,
            'treatment_name' => $record->vaccine_name,
            'record_source' => $record->record_source,
            'manual_price' => $record->manual_price,
            'inventory_quantity' => $record->inventory_quantity,
            'expected_price' => $record->expected_price,
            'expected_weight_kg' => $record->expected_weight_kg,
            'request_key' => $record->request_key,
            'strength_concentration' => $record->strength_concentration,
            'pet_id' => $record->pet_id,
            'clinic_appointment_id' => $record->clinic_appointment_id,
            'clinic_appointment_reference' => $record->clinicAppointment?->appointment_reference,
            'inventory_item_id' => $record->inventory_item_id,
            'inventory_item_name' => $record->inventoryItem?->item_name,
            'inventory_item_category' => $record->inventoryItem?->category,
            'inventory_unit' => $record->inventoryItem?->unit,
            'vaccine_name' => $record->vaccine_name,
            'product_name' => $record->product_name,
            'manufacturer' => $record->manufacturer,
            'batch_number' => $record->batch_number,
            'administered_date' => $record->administered_date?->toDateString(),
            'next_due_date' => $record->next_due_date?->toDateString(),
            'product_expiry_date' => $record->product_expiry_date?->toDateString(),
            'dose_amount' => $record->dose_amount,
            'dose_unit' => $record->dose_unit,
            'route' => $record->route,
            'administration_site' => $record->administration_site,
            'recorded_by_user_id' => $record->recorded_by_user_id,
            'recorded_by_user_name' => $record->recordedBy?->actorAttribution(),
            'notes' => $record->notes,
            'state' => ($record->voided_at !== null ? 'voided' : ($record->published_at !== null ? 'published' : 'draft')),
            'due_status' => $record->dueStatus(),
            'published_at' => $record->published_at?->toIso8601String(),
            'published_by_user_id' => $record->published_by_user_id,
            'published_by_user_name' => $record->publishedBy?->actorAttribution(),
            'voided_at' => $record->voided_at?->toIso8601String(),
            'voided_by_user_id' => $record->voided_by_user_id,
            'voided_by_user_name' => $record->voidedBy?->actorAttribution(),
            'void_reason' => $record->void_reason,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }
}
