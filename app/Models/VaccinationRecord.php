<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VaccinationRecord extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    public const DUE_SOON_DAYS = 30;

    public const STATUS_CURRENT = 'current';

    public const STATUS_DUE_SOON = 'due_soon';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_UNKNOWN = 'unknown';

    public const ADMINISTRATION_ROUTES = [
        'subcutaneous',
        'intramuscular',
        'intranasal',
        'oral',
        'other',
    ];

    protected $fillable = [
        'treatment_type',
        'inventory_quantity',
        'expected_price',
        'expected_weight_kg',
        'request_key',
        'strength_concentration',
        'pet_id',
        'clinic_appointment_id',
        'inventory_item_id',
        'vaccine_name',
        'product_name',
        'manufacturer',
        'batch_number',
        'administered_date',
        'next_due_date',
        'product_expiry_date',
        'dose_amount',
        'dose_unit',
        'route',
        'administration_site',
        'recorded_by_user_id',
        'notes',
        'published_at',
        'published_by_user_id',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected $casts = [
        'inventory_quantity' => 'decimal:2',
        'expected_price' => 'decimal:2',
        'expected_weight_kg' => 'decimal:2',
        'administered_date' => 'date',
        'next_due_date' => 'date',
        'product_expiry_date' => 'date',
        'dose_amount' => 'decimal:3',
        'published_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id', 'id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id', 'item_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id', 'user_id');
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by_user_id', 'user_id');
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by_user_id', 'user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeVisibleToClients(Builder $query): Builder
    {
        return $query->where('treatment_type', 'vaccine')->published()->notVoided();
    }

    public function hasPublishingRequirements(): bool
    {
        return filled($this->vaccine_name)
            && $this->administered_date !== null;
    }

    public function dueStatus(?CarbonInterface $asOf = null): string
    {
        if (! $this->next_due_date) {
            return self::STATUS_UNKNOWN;
        }

        $today = ($asOf ?? now())->copy()->startOfDay();
        $nextDueDate = $this->next_due_date->copy()->startOfDay();

        if ($nextDueDate->lt($today)) {
            return self::STATUS_OVERDUE;
        }

        if ($nextDueDate->lte($today->copy()->addDays(self::DUE_SOON_DAYS))) {
            return self::STATUS_DUE_SOON;
        }

        return self::STATUS_CURRENT;
    }
}
