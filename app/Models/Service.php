<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $table = 'services';

    protected $primaryKey = 'service_id';

    public $timestamps = false;

    protected $fillable = [
        'service_name',
        'slug',
        'description',
        'base_price',
        'price_small',
        'price_medium',
        'price_large',
        'price_extra_large',
        'price_min',
        'price_max',
        'is_starting_price',
        'starting_price_sizes',
        'range_price_maximums',
        'is_active',
        'kind',
        'pet_type',
        'description_items',
        'included_ala_carte_ids',
        'display_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_starting_price' => 'boolean',
        'starting_price_sizes' => 'array',
        'range_price_maximums' => 'array',
        'description_items' => 'array',
        'included_ala_carte_ids' => 'array',
        'display_order' => 'integer',
    ];

    public function getKindAttribute($value): ?string
    {
        return $value ?? config("grooming_services.services.{$this->slug}.kind");
    }

    public function pricingSizes(): array
    {
        if ($this->kind !== 'package') return [];
        if (($this->getAttributes()['kind'] ?? null) === null) {
            return array_keys(config("grooming_services.services.{$this->slug}.prices", []));
        }
        return array_values(array_filter(['small', 'medium', 'large', 'extra_large'],
            fn ($size) => $this->{'price_'.$size} !== null));
    }
}
