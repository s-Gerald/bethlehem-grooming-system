<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicConfinement extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $guarded = ['id'];

    protected $casts = ['admitted_at' => 'datetime', 'discharged_at' => 'datetime'];

    public function appointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id');
    }

    public function caseService()
    {
        return $this->belongsTo(ClinicCaseService::class, 'clinic_case_service_id');
    }

    public function observations()
    {
        return $this->hasMany(ClinicConfinementObservation::class);
    }

    public function confinementCharges()
    {
        return $this->hasMany(ClinicConfinementCharge::class);
    }
}
