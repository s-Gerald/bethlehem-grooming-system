<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicRecord extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $table = 'clinic_records';

    protected $fillable = [
        'clinic_appointment_id',
        'chief_complaint',
        'diagnosis',
        'findings',
        'treatment_given',
        'follow_up_date',
        'follow_up_notes',
        'vet_notes',
        'relevant_history', 'reported_signs', 'physical_exam_findings',
        'differential_diagnoses', 'working_diagnosis', 'primary_veterinarian_id',
        'primary_veterinarian_name', 'recorded_by_user_id', 'recorded_by_name',
        'additional_veterinarians', 'diagnostics', 'prescriptions',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'additional_veterinarians' => 'array',
        'diagnostics' => 'array',
        'prescriptions' => 'array',
    ];

    public function clinicalDetails(): array
    {
        return [
            'primary_veterinarian_id' => $this->primary_veterinarian_id,
            'primary_veterinarian_name' => $this->primary_veterinarian_name,
            'additional_veterinarians' => $this->additional_veterinarians ?? [],
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'recorded_by_name' => $this->recorded_by_name,
            'relevant_history' => $this->relevant_history,
            'reported_signs' => $this->reported_signs,
            'physical_exam_findings' => $this->physical_exam_findings ?? $this->findings,
            'differential_diagnoses' => $this->differential_diagnoses,
            'working_diagnosis' => $this->working_diagnosis ?? $this->diagnosis,
            'diagnostics' => $this->diagnostics ?? [],
            'prescriptions' => $this->prescriptions ?? [],
        ];
    }

    public function appointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(ClinicAttachment::class, 'clinic_record_id', 'id');
    }
}
