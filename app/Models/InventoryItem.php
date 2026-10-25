<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    public const RETAIL_CATEGORIES = ['food', 'grooming_supply', 'pet_shop', 'miscellaneous'];
    public const MEDICINE_TYPES = ['deworming', 'heartworm_prevention', 'heartworm_treatment', 'mange_treatment', 'general_medication'];

    public static function isMeasuredUnit(string $unit): bool
    {
        return (bool) preg_match('/^(kg|g|mg|mcg|l|ml|kilogram|gram|milligram|microgram|liter|litre|milliliter|millilitre)$/i', trim($unit));
    }

    public function scopeRetailSale($query)
    {
        return $query->where('is_active', true)
            ->whereIn('category', self::RETAIL_CATEGORIES)
            ->where('selling_price', '>', 0)
            ->where('selling_price', '<=', 999999.99);
    }

    protected $table      = 'inventory_items';
    protected $primaryKey = 'item_id';

    protected $fillable = [
        'item_name',
        'barcode',
        'category',
        'unit',
        'medicine_type',
        'strength_concentration',
        'pricing_method',
        'weight_pricing_mode',
        'description',
        'unit_cost',
        'selling_price',
        'quantity_on_hand',
        'reorder_level',
        'is_active',
    ];

    protected $casts = [
        'unit_cost'        => 'decimal:2',
        'selling_price'    => 'decimal:2',
        'quantity_on_hand' => 'decimal:2',
        'reorder_level'    => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    // ── Computed attributes ────────────────────────────────────────────────────

    public function getLowStockAttribute(): bool
    {
        if ((float) $this->reorder_level <= 0) {
            return false;
        }
        return (float) $this->quantity_on_hand <= (float) $this->reorder_level;
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function weightPrices()
    {
        return $this->hasMany(InventoryItemWeightPrice::class, 'item_id', 'item_id')->orderBy('min_weight_kg');
    }

    /** Deterministic price lookup only; never derives dosage or stock quantity. */
    public function clinicPrice($weight): array
    {
        if ($this->category !== 'medicine' || $this->pricing_method !== 'weight_based') {
            if ($this->selling_price === null || (float) $this->selling_price <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'medicine' => 'Set this product\'s selling price in Inventory.',
                ]);
            }
            return ['price' => $this->selling_price, 'pet_weight_kg' => null, 'min_weight_kg' => null, 'max_weight_kg' => null];
        }
        if (! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'weight_kg' => 'Record or update the pet\'s weight before selecting a weight-based medicine price.',
            ]);
        }
        $range = \App\Support\ProductWeightPricing::rangeFor(
            $this->weightPrices->map->only(['min_weight_kg', 'max_weight_kg', 'price'])->all(),
            $weight, $this->weight_pricing_mode ?? 'legacy',
        );
        if ($range === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'medicine' => 'No price is configured for this pet\'s weight. Correct the pricing configuration or pet weight.',
            ]);
        }
        return ['price' => $range['price'], 'pet_weight_kg' => $weight,
            'min_weight_kg' => $range['min_weight_kg'], 'max_weight_kg' => $range['max_weight_kg']];
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id');
    }

    public function vaccinationRecords()
    {
        return $this->hasMany(VaccinationRecord::class, 'inventory_item_id', 'item_id');
    }

    public function stockIns()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id')
                    ->where('type', 'stock_in');
    }

    public function stockOuts()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id')
                    ->where('type', 'stock_out');
    }

    // Returns stock_in records that have an expiry_date (for expiry monitoring)
    public function batches()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id', 'item_id')
                    ->where('type', 'stock_in')
                    ->whereNotNull('expiry_date')
                    ->orderBy('expiry_date');
    }
}
