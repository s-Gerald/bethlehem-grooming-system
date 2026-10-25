<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicClosure extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $table = 'clinic_closures';

    protected $fillable = [
        'type',
        'start_date',
        'end_date',
        'reason',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];
}
