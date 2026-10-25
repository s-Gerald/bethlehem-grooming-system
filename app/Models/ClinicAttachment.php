<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicAttachment extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $table = 'clinic_attachments';

    protected $fillable = [
        'clinic_record_id',
        'clinic_case_service_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size_bytes',
        'label',
    ];

    public function record()
    {
        return $this->belongsTo(ClinicRecord::class, 'clinic_record_id', 'id');
    }

    public function caseService()
    {
        return $this->belongsTo(ClinicCaseService::class, 'clinic_case_service_id');
    }
}
