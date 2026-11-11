<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicService;
use App\Models\GatewayPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicCaseServiceRecorder
{
    public function save(int $appointmentId, array $data, User $actor, ?int $caseServiceId = null, bool $finalizingConfinement = false, bool $useAdmissionRate = false): ClinicCaseService
    {
        abort_unless($actor->clinicPermissions()['clinical'], 403);
        $data = Validator::make($data, [
            'clinic_service_id' => $caseServiceId ? 'prohibited' : 'required|integer',
            'request_key' => $caseServiceId ? 'prohibited' : 'required|uuid',
            'status' => ['required', Rule::in(['ordered', 'performed'])],
            'quantity' => 'sometimes|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'applied_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.ClinicService::MAX_PRICE,
            'notes' => 'nullable|string|max:500',
        ])->validate();

        return DB::transaction(function () use ($appointmentId, $data, $actor, $caseServiceId, $finalizingConfinement, $useAdmissionRate) {
            $appointment = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $entry = $caseServiceId
                ? $appointment->caseServices()->whereKey($caseServiceId)->firstOrFail()
                : ClinicCaseService::where('request_key', $data['request_key'])->first();
            if ($entry && ($entry->clinic_appointment_id !== $appointment->id
                || (! $caseServiceId && (int) $entry->clinic_service_id !== (int) $data['clinic_service_id']))) {
                throw ValidationException::withMessages(['request_key' => 'This service request has already been used.']);
            }
            if ($entry && $entry->status !== 'ordered') {
                if ($entry->status === $data['status']) {
                    return $entry->load('charge');
                }
                throw ValidationException::withMessages(['status' => 'This service can no longer be finalized.']);
            }
            if ($entry && ! $finalizingConfinement && \Illuminate\Support\Facades\Schema::hasTable('clinic_confinements')
                && ($entry->confinement()->exists()
                    || (\Illuminate\Support\Facades\Schema::hasTable('clinic_confinement_charges') && $entry->confinementCharge()->exists()))) {
                throw ValidationException::withMessages(['status' => 'Finalize the base confinement service through Discharge & Send to Billing.']);
            }
            if ($appointment->paid || $appointment->archived_at || ! $appointment->clinicalContentEditableFor($actor)) {
                throw ValidationException::withMessages(['status' => 'Clinical services cannot be changed at this case stage.']);
            }
            $service = ClinicService::with('category')->whereKey($entry?->clinic_service_id ?? $data['clinic_service_id'])
                ->lockForUpdate()->firstOrFail();
            if (! $finalizingConfinement && $service->category?->name === ClinicConfinementService::CATEGORY) {
                throw ValidationException::withMessages(['clinic_service_id' => 'Add confinement charges through the Confinement tab.']);
            }
            if (! $service->is_active && ! ($finalizingConfinement && $entry)) {
                throw ValidationException::withMessages(['clinic_service_id' => 'Choose an active Clinic service.']);
            }
            if (! $entry) {
                $entry = $appointment->caseServices()->make([
                    'clinic_service_id' => $service->id, 'request_key' => $data['request_key'],
                    'quantity' => $data['quantity'] ?? 1,
                ]);
                $entry->forceFill(['recorded_by_user_id' => $actor->user_id, 'ordered_at' => $data['status'] === 'ordered' ? now() : null]);
            }
            $entry->fill(array_intersect_key($data, array_flip(['quantity', 'notes'])));
            $entry->forceFill(['status' => $data['status']]);
            if ($data['status'] === 'performed') {
                if ($useAdmissionRate && ($entry->applied_price === null || ! $entry->service_snapshot)) {
                    throw ValidationException::withMessages(['confinement' => 'The admission rate is unavailable. Contact an admin before discharge.']);
                }
                $pricing = $useAdmissionRate
                    ? ['price' => $entry->applied_price, 'snapshot' => $entry->service_snapshot]
                    : app(ClinicServicePriceResolver::class)->resolve($service, $appointment, $data['applied_price'] ?? null);
                $entry->forceFill([
                    'performed_at' => now(), 'performed_by_user_id' => $actor->user_id,
                    'applied_price' => $pricing['price'], 'service_snapshot' => $pricing['snapshot'],
                ]);
            }
            return $this->persist($appointment, $entry, $service->name);
        });
    }

    public function bulk(int $anchor, array $data, User $actor, ClinicGroupClinicalPatients $scope): Collection
    {
        return DB::transaction(function () use ($anchor, $data, $actor, $scope) {
            $group = $scope->group($anchor, $actor, lock: true);
            $patients = $scope->locked($group, array_column($data['patients'], 'id'));
            $requests = collect($data['patients'])->keyBy('id');
            $retries = ClinicCaseService::with('charge')->whereIn('request_key', $requests->pluck('request_key'))->get()->keyBy('request_key');
            if ($retries->count() !== $patients->count()) GatewayPayment::assertClinicUnlocked($patients->first());
            $service = ClinicService::with('category')->lockForUpdate()->findOrFail($data['clinic_service_id']);
            $resolver = app(ClinicServicePriceResolver::class);
            $billing = app(ClinicBillingService::class);
            $prepared = $patients->map(function (ClinicAppointment $patient) use ($actor, $scope, $service, $requests, $retries, $resolver, $billing) {
                $input = $requests->get($patient->id);
                try {
                    $entry = $retries->get($input['request_key']);
                    if ($entry) {
                        if ((int) $entry->clinic_appointment_id === $patient->id
                            && (int) $entry->clinic_service_id === $service->id && $entry->status === 'performed') {
                            return [$entry, null];
                        }
                        throw ValidationException::withMessages(['request_key' => 'This service request has already been used.']);
                    }
                    if (! $scope->editable($patient, $actor)) {
                        throw ValidationException::withMessages(['status' => 'Clinical services cannot be changed at this case stage.']);
                    }
                    if (! $service->is_active || $service->category?->name === ClinicConfinementService::CATEGORY) {
                        throw ValidationException::withMessages(['clinic_service_id' => 'Choose an active service outside Confinement.']);
                    }
                    $shot = $service->billing_unit === 'shot';
                    validator($input, ['quantity' => $shot ? 'required|integer|min:1|max:99999999' : 'prohibited'])->validate();
                    $quantity = $shot ? $input['quantity'] : 1;
                    $pricing = $resolver->resolve($service, $patient, $input['applied_price'] ?? null);
                    if ((float) $pricing['price'] !== (float) $input['expected_price']) {
                        throw ValidationException::withMessages(['price' => 'The service price changed. Review the price again.']);
                    }
                    $total = round((float) $patient->charges_sum_amount + (float) $billing->lineAmount($quantity, $pricing['price']), 2);
                    if ($total > 99999999.99) {
                        throw ValidationException::withMessages(['charges' => 'The case charges exceed the supported total.']);
                    }
                    $entry = $patient->caseServices()->make([
                        'clinic_service_id' => $service->id, 'request_key' => $input['request_key'],
                        'quantity' => $quantity,
                    ])->forceFill([
                        'status' => 'performed',
                        'recorded_by_user_id' => $actor->user_id, 'performed_by_user_id' => $actor->user_id,
                        'performed_at' => now(), 'applied_price' => $pricing['price'], 'service_snapshot' => $pricing['snapshot'],
                    ]);

                    return [$entry, $total];
                } catch (ValidationException $error) {
                    $scope->fail($patient, $error);
                }
            });

            return $prepared->map(fn ($row, $id) => $row[0]->exists
                ? $row[0] : $this->persist($patients->get($id), $row[0], $service->name, $row[1]))->values();
        }, 3);
    }

    private function persist(ClinicAppointment $appointment, ClinicCaseService $entry, string $serviceName, ?float $total = null): ClinicCaseService
    {
        $entry->save();
        if ($entry->status === 'performed') {
            $billing = app(ClinicBillingService::class);
            $charge = $appointment->charges()->make([
                'kind' => 'service', 'clinic_service_id' => $entry->clinic_service_id,
                'description' => $entry->service_snapshot['name'] ?? $serviceName,
                'quantity' => $entry->quantity, 'unit_price' => $entry->applied_price,
                'amount' => $billing->lineAmount($entry->quantity, $entry->applied_price),
            ]);
            $charge->forceFill(['clinic_case_service_id' => $entry->id])->save();
            if ($total === null) {
                $billing->updateTotal($appointment);
            } else {
                $appointment->update(['total_amount' => $total]);
            }
            return $entry->setRelation('charge', $charge);
        }

        return $entry->load('charge');
    }

    public function remove(int $appointmentId, int $caseServiceId, User $actor): void
    {
        abort_unless($actor->clinicPermissions()['clinical'], 403);
        DB::transaction(function () use ($appointmentId, $caseServiceId, $actor) {
            $appointment = ClinicAppointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
            $entry = $appointment->caseServices()->whereKey($caseServiceId)->firstOrFail();
            if ($appointment->paid || $appointment->archived_at || ! $appointment->clinicalContentEditableFor($actor)) {
                throw ValidationException::withMessages(['services' => 'Services cannot be removed at this case stage.']);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('clinic_confinements') && ($entry->confinement()->exists()
                || (\Illuminate\Support\Facades\Schema::hasTable('clinic_confinement_charges') && $entry->confinementCharge()->exists()))) {
                throw ValidationException::withMessages(['services' => 'Confinement services cannot be removed here.']);
            }
            if ($entry->status !== 'performed') {
                throw ValidationException::withMessages(['services' => 'Only manually added services can be removed here.']);
            }
            $entry->charge()->delete();
            $entry->delete();
            app(ClinicBillingService::class)->updateTotal($appointment);
        });
    }
}
