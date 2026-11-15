<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicConsent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['signature_strokes'];

    protected $casts = [
        'clinic_consent' => 'boolean',
        'signature_strokes' => 'array',
        'agreement_snapshot' => 'array',
        'signed_at' => 'immutable_datetime',
    ];
}
