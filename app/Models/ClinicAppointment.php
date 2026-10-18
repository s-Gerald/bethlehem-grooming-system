<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ClinicAppointment extends Model
{
    public const TERMINAL_STATUSES = ['completed', 'cancelled', 'no_show', 'expired', 'archived'];

    public const OPERATIONAL_STATUSES = ['checked_in', 'in_consultation', 'for_payment'];

    public const CLINICAL_CONTENT_EDITABLE_STATUSES = [
        'in_consultation',
    ];

    public const COMPLETION_VITAL_FIELDS = ['weight_kg', 'temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm'];

    protected $table = 'clinic_appointments';

    protected $fillable = [
        'appointment_reference',
        'appointment_type',
        'case_type',
        'status',
        'queue_number',
        'appointment_date',
        'window_id',
        'user_id',
        'walkin_id',
        'pet_id',
        'chief_complaint',
        'common_concerns',
        'total_amount',
        'paid',
        'payment_method',
        'gateway_payment_id',
        'cash_received',
        'paid_by_user_id',
        'paid_at',
        'notes',
        'checked_in_at',
        'consultation_started_at',
        'consultation_finished_at',
        'archived_at',
    ];

    protected $casts = [
        'common_concerns'         => 'array',
        'paid'                    => 'boolean',
        'paid_at'                 => 'datetime',
        'cash_received'           => 'decimal:2',
        'appointment_date'        => 'date',
        'checked_in_at'           => 'datetime',
        'consultation_started_at' => 'datetime',
        'consultation_finished_at'=> 'datetime',
        'archived_at'             => 'datetime',
    ];

    // Run PreRegistrationExpiry before using this scope for intake or current-case lookup.
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPERATIONAL_STATUSES);
    }

    public function scopeExpected(Builder $query): Builder
    {
        return $query->where('status', 'waiting_to_arrive')
            ->whereDate('appointment_date', now()->toDateString());
    }

    public function scopeConfined(Builder $query): Builder
    {
        return $query->blocking()
            ->whereHas('confinement', fn (Builder $episode) => $episode->whereNull('discharged_at'));
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function walkin()
    {
        return $this->belongsTo(Walkin::class, 'walkin_id', 'id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id', 'user_id');
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

    public function timeWindow()
    {
        return $this->belongsTo(TimeWindow::class, 'window_id', 'window_id');
    }

    public function record()
    {
        return $this->hasOne(ClinicRecord::class, 'clinic_appointment_id', 'id');
    }

    public function vitals()
    {
        return $this->hasOne(ClinicVital::class, 'clinic_appointment_id', 'id');
    }

    public function vaccinationRecords()
    {
        return $this->hasMany(VaccinationRecord::class, 'clinic_appointment_id', 'id');
    }

    public function gatewayPayment()
    {
        return $this->belongsTo(GatewayPayment::class);
    }

    public function charges()
    {
        return $this->hasMany(ClinicCharge::class);
    }

    public function caseServices()
    {
        return $this->hasMany(ClinicCaseService::class);
    }

    public function confinement()
    {
        return $this->hasOne(ClinicConfinement::class);
    }

    public function hasActiveConfinement(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('clinic_confinements')
            && $this->confinement()->whereNull('discharged_at')->exists();
    }

    public function clinicalContentEditableFor(User $actor): bool
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('gateway_payments')
            && GatewayPayment::forPayable($this, 'clinic')->where('status', 'pending')->exists()) return false;
        return in_array($this->status, self::CLINICAL_CONTENT_EDITABLE_STATUSES, true)
            || ($actor->role === 'admin' && $this->status === 'for_payment');
    }

    public function prepareForPayment(): void
    {
        if ($this->hasActiveConfinement()) {
            throw ValidationException::withMessages(['status' => 'Discharge the confined patient before sending this case to Billing.']);
        }
        if ($this->status !== 'in_consultation') {
            throw ValidationException::withMessages(['status' => 'Start the consultation before finishing it.']);
        }
        $this->assertCompletionVitals();
        if (\Illuminate\Support\Facades\Schema::hasTable('vaccination_records')) {
            app(\App\Services\ClinicTreatmentFinalizer::class)->finalizeVisit($this, auth()->user());
        }
        $total = (float) $this->charges()->sum('amount');
        if ($total > 99999999.99) {
            throw ValidationException::withMessages(['charges' => 'The case charges exceed the supported total.']);
        }
        $this->update([
            'status' => 'for_payment',
            'total_amount' => $total,
            'consultation_finished_at' => now(),
        ]);
        Notification::createForClinic($this, Notification::TYPE_CLINIC_PAYMENT_DUE,
            "Clinic visit {$this->appointment_reference} is ready for payment.");
    }

    public function assertCompletionVitals(): void
    {
        if ($this->case_type === 'vaccination') {
            return;
        }

        $vitals = $this->vitals()->first();
        $errors = [];
        if (! $this->record?->primary_veterinarian_id) {
            $errors['primary_veterinarian_id'] = 'Select a Primary Attending Veterinarian in the Medical Record before finishing this case.';
        }
        foreach (self::COMPLETION_VITAL_FIELDS as $field) {
            if ($vitals?->{$field} === null) {
                $errors[$field] = 'Save the required consultation vitals in the Medical Record before finishing this case.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
