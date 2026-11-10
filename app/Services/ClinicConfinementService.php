<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicConfinement;
use App\Models\ClinicConfinementCharge;
use App\Models\ClinicConfinementObservation;
use App\Models\ClinicService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClinicConfinementService
{
    public const CATEGORY = 'Confinement';
    public const RELATIONS = ['caseService.clinicService.category', 'caseService.charge',
        'confinementCharges.caseService.clinicService.category', 'confinementCharges.caseService.charge'];

    private function catalogue(string $behavior)
    {
        return ClinicService::with('category')->where('is_active', true)->where('charge_behavior', $behavior)
            ->whereHas('category', fn ($q) => $q->where('name', self::CATEGORY));
    }

    private function baseService(): ClinicService
    {
        $services = $this->catalogue('base_confinement')->lockForUpdate()->get();
        if ($services->count() !== 1) {
            throw ValidationException::withMessages(['confinement' => $services->isEmpty()
                ? 'Configure one active Base Confinement service in Clinic Services before admission.'
                : 'More than one active Base Confinement service exists. Ask an admin to keep only one active in Clinic Services.']);
        }
        $service = $services->first();
        if ($service->pricing_method !== 'tiered' || $service->tier_basis !== 'weight_kg' || $service->billing_unit !== 'day') {
            throw ValidationException::withMessages(['confinement' => 'Configure confinement weight cutoffs and daily prices in Clinic Services first.']);
        }
        \App\Support\ConfinementWeightPricing::normalize($service->tiers ?? []);
        return $service;
    }

    public function admissionPreview(ClinicAppointment $case): array
    {
        $service = $this->baseService();
        return ['data' => $service, 'pricing' => app(ClinicServicePriceResolver::class)->preview($service, $case)];
    }

    public function admit(int $appointmentId, array $data, User $actor): ClinicConfinement
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, [
            'request_key' => 'required|uuid', 'reason' => 'required|string|max:500', 'notes' => 'nullable|string|max:500',
            'clinic_service_id' => 'prohibited', 'quantity' => 'prohibited', 'applied_price' => 'prohibited',
            'admitted_at' => 'prohibited', 'attending_veterinarian_id' => 'prohibited', 'pet_size' => 'prohibited',
        ])->validate();
        return DB::transaction(function () use ($appointmentId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $existing = $case->confinement()->with('caseService')->first();
            if ($existing) {
                if ($existing->caseService->request_key === $data['request_key']) return $existing->load(self::RELATIONS);
                throw ValidationException::withMessages(['confinement' => 'This case already has a confinement episode. Open its existing history.']);
            }
            $this->assertOngoing($case);
            $record = $case->record;
            if (! $record?->primary_veterinarian_id) {
                throw ValidationException::withMessages(['primary_veterinarian_id' => 'Complete the Primary Attending Veterinarian in the Medical Record before admission.']);
            }
            $service = $this->baseService();
            $pricing = app(ClinicServicePriceResolver::class)->resolve($service, $case);
            $entry = app(ClinicCaseServiceRecorder::class)->save($case->id, [
                'clinic_service_id' => $service->id, 'request_key' => $data['request_key'], 'status' => 'ordered', 'quantity' => 1,
            ], $actor, finalizingConfinement: true);
            if (! $entry->wasRecentlyCreated) {
                throw ValidationException::withMessages(['request_key' => 'Use a new request key for this confinement admission.']);
            }
            $entry->forceFill(['applied_price' => $pricing['price'], 'service_snapshot' => $pricing['snapshot']])->save();
            $episode = $case->confinement()->create([
                'clinic_case_service_id' => $entry->id, 'admitted_at' => now(),
                'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
                'attending_veterinarian_id' => $record->primary_veterinarian_id,
                'attending_veterinarian_name' => $record->primary_veterinarian_name,
                'admitted_by_user_id' => $actor->user_id,
            ]);
            foreach ($this->catalogue('admission_charge')->lockForUpdate()->get() as $admission) {
                // Variable prices remain pending until their final price is supplied at discharge.
                $variable = in_array($admission->pricing_method, ['range', 'starting_at'], true);
                $entry = app(ClinicCaseServiceRecorder::class)->save($case->id, [
                    'clinic_service_id' => $admission->id, 'request_key' => (string) Str::uuid(),
                    'status' => $variable ? 'ordered' : 'performed', 'quantity' => 1,
                ], $actor, finalizingConfinement: true);
                $episode->confinementCharges()->create(['clinic_case_service_id' => $entry->id, 'charge_behavior' => 'admission_charge']);
            }
            return $episode->load(self::RELATIONS);
        });
    }

    public function recordObservation(int $appointmentId, array $data, User $actor): ClinicConfinementObservation
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, [
            'request_key' => 'required|uuid', 'temperature_c' => 'nullable|numeric|decimal:0,1|min:0|max:999.9',
            'heart_rate_bpm' => 'nullable|integer|min:0|max:65535', 'respiratory_rate_bpm' => 'nullable|integer|min:0|max:65535',
            'notes' => 'nullable|string|max:500', 'recorded_at' => 'prohibited',
        ])->validate();
        if (collect($data)->except('request_key')->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->isEmpty()) {
            throw ValidationException::withMessages(['observation' => 'Record at least one clinical value or observation.']);
        }
        return DB::transaction(function () use ($appointmentId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $episode = $case->confinement()->lockForUpdate()->firstOrFail();
            $existing = ClinicConfinementObservation::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->clinic_confinement_id !== $episode->id) {
                    throw ValidationException::withMessages(['request_key' => 'This monitoring request has already been used.']);
                }
                return $existing;
            }
            $this->assertOngoing($case);
            if ($episode->discharged_at) throw ValidationException::withMessages(['confinement' => 'This confinement has already been discharged.']);
            return $episode->observations()->create([...$data, 'recorded_at' => now(), 'recorded_by_user_id' => $actor->user_id]);
        });
    }

    public function dischargePreview(ClinicAppointment $case): array
    {
        $episode = $case->confinement()->with(self::RELATIONS)->firstOrFail();
        $this->assertOngoing($case);
        if ($episode->discharged_at) throw ValidationException::withMessages(['confinement' => 'This confinement has already been discharged.']);
        $service = $episode->caseService->clinicService;
        $end = now();
        return [
            'data' => $episode, 'service' => $service,
            'pricing' => app(ClinicServicePriceResolver::class)->confinementPreview($episode->caseService),
            'quantity' => app(ConfinementBillingDuration::class)->billableDays($episode->admitted_at, $end),
            'duration_seconds' => max(0, $end->getTimestamp() - $episode->admitted_at->getTimestamp()),
            'charges' => $episode->confinementCharges->filter(fn ($extra) => ! $extra->removed_at && $extra->caseService->status === 'ordered')
                ->map(fn ($extra) => ['id' => $extra->id, 'service' => $extra->caseService->clinicService,
                    'quantity' => $extra->started_at ? app(ConfinementBillingDuration::class)->billableDays($extra->started_at, $end) : 1,
                    'pricing' => app(ClinicServicePriceResolver::class)->preview($extra->caseService->clinicService, $case)])->values(),
        ];
    }

    public function addCharge(int $appointmentId, array $data, User $actor): ClinicConfinementCharge
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, [
            'request_key' => 'required|uuid', 'clinic_service_id' => 'required|integer',
            'applied_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.ClinicService::MAX_PRICE,
            'quantity' => 'prohibited', 'started_at' => 'prohibited', 'stopped_at' => 'prohibited',
        ])->validate();
        return DB::transaction(function () use ($appointmentId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $episode = $case->confinement()->lockForUpdate()->firstOrFail();
            $existing = ClinicCaseService::where('request_key', $data['request_key'])->first();
            if ($existing) {
                $extra = $episode->confinementCharges()->where('clinic_case_service_id', $existing->id)->first();
                if (! $extra || (int) $existing->clinic_service_id !== (int) $data['clinic_service_id']) {
                    throw ValidationException::withMessages(['request_key' => 'This charge request has already been used.']);
                }
                return $extra->load('caseService.charge');
            }
            $this->assertActive($case, $episode);
            $service = $this->catalogue('additional_charge')->whereKey($data['clinic_service_id'])->lockForUpdate()->first();
            if (! $service || ! in_array($service->billing_unit, [null, 'service', 'day'], true)) {
                throw ValidationException::withMessages(['clinic_service_id' => 'Choose an active Additional Charge from Confinement in Clinic Services.']);
            }
            $timed = $service->billing_unit === 'day';
            if ($timed && $episode->confinementCharges()->whereNull('stopped_at')->whereNull('removed_at')->whereNotNull('started_at')
                ->whereHas('caseService', fn ($q) => $q->where('clinic_service_id', $service->id))->exists()) {
                throw ValidationException::withMessages(['clinic_service_id' => 'This daily charge is already running. Stop it before starting it again.']);
            }
            if ($timed) {
                $preview = app(ClinicServicePriceResolver::class)->preview($service, $case);
                if ($preview['error']) throw ValidationException::withMessages(['clinic_service_id' => $preview['error']]);
            }
            $entry = app(ClinicCaseServiceRecorder::class)->save($case->id, [
                ...$data, 'status' => $timed ? 'ordered' : 'performed', 'quantity' => 1,
            ], $actor, finalizingConfinement: true);
            return $episode->confinementCharges()->create(['clinic_case_service_id' => $entry->id,
                'charge_behavior' => 'additional_charge', 'started_at' => $timed ? now() : null])->load('caseService.charge');
        });
    }

    public function stopCharge(int $appointmentId, int $chargeId, array $data, User $actor): ClinicConfinementCharge
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, ['applied_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.ClinicService::MAX_PRICE,
            'quantity' => 'prohibited', 'stopped_at' => 'prohibited'])->validate();
        return DB::transaction(function () use ($appointmentId, $chargeId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $episode = $case->confinement()->lockForUpdate()->firstOrFail();
            $extra = $episode->confinementCharges()->whereKey($chargeId)->firstOrFail();
            if ($extra->stopped_at || $extra->removed_at) return $extra->load('caseService.charge');
            $this->assertActive($case, $episode);
            if (! $extra->started_at) throw ValidationException::withMessages(['charge' => 'Only a running daily charge can be stopped.']);
            $this->finalizeCharge($case, $extra, now(), $data['applied_price'] ?? null, $actor);
            return $extra->load('caseService.charge');
        });
    }

    public function removeCharge(int $appointmentId, int $chargeId, User $actor): void
    {
        $this->assertClinical($actor);
        DB::transaction(function () use ($appointmentId, $chargeId, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $episode = $case->confinement()->lockForUpdate()->firstOrFail();
            $extra = $episode->confinementCharges()->whereKey($chargeId)->firstOrFail();
            if ($extra->removed_at) return;
            if ($case->paid || $case->archived_at || ! $case->clinicalContentEditableFor($actor)) {
                throw ValidationException::withMessages(['charge' => 'Charges cannot be removed at this case stage.']);
            }
            if ($extra->charge_behavior !== 'additional_charge') throw ValidationException::withMessages(['charge' => 'Automatic admission charges cannot be removed here.']);
            $entry = $extra->caseService;
            $entry->charge()->delete();
            $entry->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->user_id])->save();
            $extra->update(['removed_at' => now()]);
            app(ClinicBillingService::class)->updateTotal($case);
        });
    }

    private function finalizeCharge(ClinicAppointment $case, ClinicConfinementCharge $extra, \Carbon\CarbonInterface $end, mixed $price, User $actor): void
    {
        if ($extra->removed_at || $extra->caseService->status !== 'ordered') return;
        app(ClinicCaseServiceRecorder::class)->save($case->id, ['status' => 'performed',
            'quantity' => $extra->started_at ? app(ConfinementBillingDuration::class)->billableDays($extra->started_at, $end) : 1,
            'applied_price' => $price], $actor, $extra->clinic_case_service_id, finalizingConfinement: true);
        if ($extra->started_at) $extra->update(['stopped_at' => $end]);
    }

    public function discharge(int $appointmentId, array $data, User $actor): ClinicConfinement
    {
        $this->assertClinical($actor);
        $data = Validator::make($data, [
            'applied_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.ClinicService::MAX_PRICE,
            'charge_prices' => 'sometimes|array', 'charge_prices.*' => 'nullable|numeric|decimal:0,2|min:0|max:'.ClinicService::MAX_PRICE,
            'discharge_note' => 'nullable|string|max:500', 'quantity' => 'prohibited',
            'billable_days' => 'prohibited', 'discharged_at' => 'prohibited',
        ])->validate();
        return DB::transaction(function () use ($appointmentId, $data, $actor) {
            $case = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $episode = $case->confinement()->lockForUpdate()->firstOrFail();
            if ($episode->discharged_at) return $episode->load(self::RELATIONS);
            $this->assertActive($case, $episode);
            $end = now();
            $case->assertCompletionVitals();
            app(ClinicCaseServiceRecorder::class)->save($case->id, [
                'status' => 'performed', 'quantity' => app(ConfinementBillingDuration::class)->billableDays($episode->admitted_at, $end),
                'applied_price' => $data['applied_price'] ?? null,
            ], $actor, $episode->clinic_case_service_id, finalizingConfinement: true, useAdmissionRate: true);
            foreach ($episode->confinementCharges()->with('caseService')->get() as $extra) {
                $this->finalizeCharge($case, $extra, $end, $data['charge_prices'][$extra->id] ?? null, $actor);
            }
            $episode->update(['discharged_at' => $end, 'discharged_by_user_id' => $actor->user_id,
                'discharge_note' => $data['discharge_note'] ?? null]);
            $case->prepareForPayment();
            return $episode->load(self::RELATIONS);
        });
    }

    private function assertActive(ClinicAppointment $case, ClinicConfinement $episode): void
    {
        $this->assertOngoing($case);
        if ($episode->discharged_at) throw ValidationException::withMessages(['confinement' => 'This confinement has already been discharged.']);
    }

    private function assertOngoing(ClinicAppointment $case): void
    {
        if ($case->status !== 'in_consultation' || $case->paid || $case->archived_at) {
            throw ValidationException::withMessages(['status' => 'Confinement actions require an ongoing, unpaid and unarchived case in consultation.']);
        }
    }

    private function assertClinical(User $actor): void
    {
        abort_unless($actor->clinicPermissions()['clinical'], 403);
    }
}
