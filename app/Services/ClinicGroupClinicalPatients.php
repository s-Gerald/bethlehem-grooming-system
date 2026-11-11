<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicVisitGroup;
use App\Models\GatewayPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ClinicGroupClinicalPatients
{
    public function group(int $appointmentId, User $actor, bool $lock = false): ClinicVisitGroup
    {
        abort_unless($actor->clinicPermissions()['clinical'], 403);
        $anchor = ClinicAppointment::findOrFail($appointmentId);
        abort_unless($anchor->clinic_visit_group_id, 404);

        return ClinicVisitGroup::query()->when($lock, fn ($query) => $query->lockForUpdate())
            ->findOrFail($anchor->clinic_visit_group_id);
    }

    public function preview(ClinicVisitGroup $group, User $actor): Collection
    {
        $patients = $group->appointments()->with(['pet', 'vitals'])->orderBy('id')->get();
        if ($patients->isEmpty() || GatewayPayment::clinicPaymentPending($patients->first())) {
            return collect();
        }

        return $patients->filter(fn ($patient) => $this->editable($patient, $actor))->values();
    }

    public function locked(ClinicVisitGroup $group, array $ids): Collection
    {
        $patients = $group->appointments()->whereKey($ids)->with(['pet', 'vitals'])->withSum('charges', 'amount')
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($patients->count() !== count($ids)) {
            throw ValidationException::withMessages(['patients' => 'Select patients from this clinic owner session.']);
        }

        return $patients;
    }

    public function editable(ClinicAppointment $patient, User $actor): bool
    {
        return $patient->pet && ! $patient->paid && ! $patient->archived_at && $patient->clinicalContentEditableAtStage($actor);
    }

    public function fail(ClinicAppointment $patient, ValidationException $error): never
    {
        $name = $patient->pet?->pet_name ?: ($patient->appointment_reference ?: 'Patient #'.$patient->id);
        $errors = [];
        foreach ($error->errors() as $field => $messages) {
            $errors["patients.{$patient->id}.{$field}"] = array_map(fn ($message) => "{$name} — {$message}", $messages);
        }
        throw ValidationException::withMessages($errors);
    }
}
