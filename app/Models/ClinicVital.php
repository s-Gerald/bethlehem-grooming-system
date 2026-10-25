<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicVital extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $table = 'clinic_vitals';

    protected $fillable = [
        'clinic_appointment_id',
        'weight_kg',
        'temperature_c',
        'heart_rate_bpm',
        'respiratory_rate_bpm',
        'body_condition_score',
    ];

    protected $casts = [
        'weight_kg'     => 'decimal:2',
        'temperature_c' => 'decimal:1',
    ];
}
