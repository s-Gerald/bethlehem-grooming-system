<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicService extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    public const MAX_PRICE = 100000;

    protected $fillable = [
        'name', 'category_id', 'identity_key', 'pricing_method', 'billing_unit',
        'price', 'min_price', 'max_price', 'tier_basis', 'tiers', 'is_active', 'notes', 'charge_behavior',
    ];

    protected $casts = [
        'price' => 'decimal:2', 'min_price' => 'decimal:2', 'max_price' => 'decimal:2',
        'tiers' => 'array', 'is_active' => 'boolean',
    ];

    protected $hidden = ['identity_key'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ClinicServiceCategory::class, 'category_id');
    }
}
