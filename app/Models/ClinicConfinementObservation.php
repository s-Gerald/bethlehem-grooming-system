<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicConfinementObservation extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime', 'temperature_c' => 'decimal:1'];

    public function confinement()
    {
        return $this->belongsTo(ClinicConfinement::class, 'clinic_confinement_id');
    }
}
