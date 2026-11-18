<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicConfinementObservation extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime', 'temperature_c' => 'decimal:1', 'verification_history' => 'array'];

    protected $appends = ['recorded_by_name'];

    protected $hidden = ['recordedBy'];

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id', 'user_id');
    }

    public function getRecordedByNameAttribute(): ?string
    {
        return $this->relationLoaded('recordedBy') ? $this->recordedBy?->actorAttribution() : null;
    }

    public function confinement()
    {
        return $this->belongsTo(ClinicConfinement::class, 'clinic_confinement_id');
    }
}
