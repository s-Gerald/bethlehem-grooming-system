<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicVisitGroup;
use App\Models\GatewayPayment;
use App\Models\Notification;
use App\Support\PetProfileSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ClinicPaymentSettlementService
{
    public function __construct(private readonly GroomingPaymentReadinessService $money) {}

    public function patients(ClinicVisitGroup $group, bool $lock = false): Collection
    {
        return $group->appointments()->orderBy('id')
            ->with(['pet', 'charges' => fn ($query) => $query->orderBy('id')])
            ->when(Schema::hasTable('clinic_confinements'), fn ($query) => $query->withExists([
                'confinement as active_confinement' => fn ($episode) => $episode->whereNull('discharged_at'),
            ]))
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
    }

    public function eligible(Collection $patients): Collection
    {
        return $patients->where('status', '!=', 'cancelled')->values();
    }

    public function ready(ClinicAppointment $patient): bool
    {
        return $patient->status === 'for_payment' && ! $patient->archived_at && ! $patient->paid && ! $patient->active_confinement;
    }

    public function assertReady(Collection $patients): void
    {
        if ($patients->isEmpty() || ! $patients->every(fn ($patient) => $this->ready($patient))) {
            throw ValidationException::withMessages(['payment' => 'Every non-cancelled patient must be ready for payment.']);
        }
    }

    public function subtotal(ClinicAppointment $patient): int
    {
        return $patient->charges->sum(fn ($charge) => $this->money->moneyToCents($charge->amount));
    }

    public function breakdown(ClinicVisitGroup $group, Collection $patients): array
    {
        $eligible = $this->eligible($patients);
        $ready = $eligible->filter(fn ($patient) => $this->ready($patient))->count();
        $rows = $patients->map(function (ClinicAppointment $patient) {
            $historical = in_array($patient->status, ClinicAppointment::TERMINAL_STATUSES, true);
            $pet = $historical ? PetProfileSnapshot::pet($patient->pet_profile_snapshot, $patient->pet_id) : $patient->pet;
            return [
                'id' => $patient->id,
                'reference' => $patient->appointment_reference,
                'pet_name' => $pet?->pet_name,
                'status' => $patient->status,
                'confined' => (bool) $patient->active_confinement,
                'subtotal' => $this->currency($this->subtotal($patient)),
                'charges' => $patient->charges,
            ];
        });

        return [
            'id' => $group->id,
            'patients' => $rows->values(),
            'ready_count' => $ready,
            'eligible_count' => $eligible->count(),
            'can_pay' => $eligible->isNotEmpty() && $ready === $eligible->count() && ! $group->paid_at,
            'total_amount' => $group->total_amount ?? $this->currency($eligible->sum(fn ($patient) => $this->subtotal($patient))),
            'payment_method' => $group->payment_method,
            'paid_at' => $group->paid_at?->toIso8601String(),
            'cash_received' => $group->cash_received,
            'change' => $group->cash_received !== null ? round((float) $group->cash_received - (float) $group->total_amount, 2) : null,
        ];
    }

    public function currency(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function notifyWhenGroupIsReady(int $groupId): void
    {
        if (! Schema::hasColumn('notifications', 'clinic_appointment_id')) return;

        DB::afterCommit(function () use ($groupId) {
            DB::transaction(function () use ($groupId) {
                $group = ClinicVisitGroup::query()->lockForUpdate()->find($groupId);
                if (! $group || $group->paid_at) return;
                $patients = $group->appointments()->orderBy('id')->with('pet:pet_id,pet_name')
                    ->when(Schema::hasTable('clinic_confinements'), fn ($query) => $query->withExists([
                        'confinement as active_confinement' => fn ($episode) => $episode->whereNull('discharged_at'),
                    ]))->get();
                $eligible = $this->eligible($patients);
                if ($eligible->isEmpty() || ! $eligible->every(fn ($patient) => $this->ready($patient))) return;

                $anchor = $patients->first();
                if (Notification::where('clinic_appointment_id', $anchor->id)->where('type', Notification::TYPE_CLINIC_PAYMENT_DUE)->exists()) return;
                $names = ClinicVisitGroup::patientNames($eligible->pluck('pet'));
                Notification::createForClinic($anchor, Notification::TYPE_CLINIC_PAYMENT_DUE,
                    "Clinic payment is ready for {$eligible->count()} pets: {$names}.");
            }, 3);
        });
    }

    /** Caller holds the group lock and all patient locks. */
    public function settleGroup(ClinicVisitGroup $group, Collection $patients, string $method, ?int $actorId, ?string $cash = null, ?GatewayPayment $gateway = null): void
    {
        $this->assertReady($patients);
        $paidAt = $gateway?->paid_at ?? now();
        $subtotals = $patients->mapWithKeys(fn ($patient) => [$patient->id => $this->subtotal($patient)]);
        $total = $this->currency($subtotals->sum());

        if ($gateway && ($gateway->amount !== $total
            || collect($gateway->snapshot['patients'])->mapWithKeys(fn ($row) => [$row['id'] => $row['subtotal']])->all()
                !== $subtotals->map(fn ($cents) => $this->currency($cents))->all())) {
            throw ValidationException::withMessages(['payment' => 'The clinic payment breakdown changed.']);
        }

        $group->update([
            'total_amount' => $total, 'payment_method' => $method, 'paid_at' => $paidAt,
            'cash_received' => $cash, 'paid_by_user_id' => $actorId, 'gateway_payment_id' => $gateway?->id,
        ]);
        foreach ($patients as $patient) {
            $patient->update([
                'status' => 'completed', 'total_amount' => $this->currency($subtotals[$patient->id]), 'paid' => true,
                'payment_method' => $method, 'paid_by_user_id' => $actorId, 'paid_at' => $paidAt,
            ]);
        }

        $names = ClinicVisitGroup::patientNames($patients->pluck('pet'));
        Notification::createForClinic($patients->first(), Notification::TYPE_CLINIC_PAYMENT_CONFIRMED,
            "Clinic payment received for {$patients->count()} pets: {$names}.");
    }

    /** Caller holds the case lock and has validated cash or a signed paid webhook. */
    public function settle(ClinicAppointment $appointment, string $total, string $method, ?int $actorId, ?string $cash = null, ?GatewayPayment $gateway = null): void
    {
        $appointment->update([
            'status' => 'completed', 'total_amount' => $total, 'paid' => true,
            'payment_method' => $method, 'cash_received' => $cash,
            'paid_by_user_id' => $actorId, 'paid_at' => $gateway?->paid_at ?? now(),
            ...($gateway ? ['gateway_payment_id' => $gateway->id] : []),
        ]);
        Notification::createForClinic($appointment, Notification::TYPE_CLINIC_PAYMENT_CONFIRMED,
            "Payment received for clinic appointment {$appointment->appointment_reference}.");
    }
}
