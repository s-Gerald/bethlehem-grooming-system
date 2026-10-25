<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['changes' => 'array', 'operational' => 'boolean', 'occurred_at' => 'datetime'];

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Activity records cannot be edited.'));
        static::deleting(fn () => throw new \LogicException('Activity records cannot be deleted.'));
    }
}
