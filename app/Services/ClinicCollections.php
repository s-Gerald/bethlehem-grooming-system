<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use App\Models\ClinicVisitGroup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClinicCollections
{
    public function settlements(bool $requireFinishedVisit = false): Builder
    {
        $legacy = $this->confirmed('clinic_appointments', ClinicAppointment::class)
            ->where('c.status', 'completed')->where('c.paid', true)
            ->when($requireFinishedVisit, fn ($query) => $query->whereNotNull('c.consultation_finished_at'));

        if (Schema::hasTable('clinic_visit_groups')) {
            $legacy->whereNull('c.clinic_visit_group_id');
            $legacy->unionAll($this->groups());
        }

        return DB::query()->fromSub($legacy, 'clinic_settlements');
    }

    public function visits(): Builder
    {
        $legacy = $this->confirmed('clinic_appointments', ClinicAppointment::class)
            ->where('c.status', 'completed')->where('c.paid', true)
            ->whereNotNull('c.consultation_finished_at')->addSelect('c.consultation_finished_at');

        if (Schema::hasTable('clinic_visit_groups')) {
            $legacy->whereNull('c.clinic_visit_group_id');
            $legacy->unionAll(DB::table('clinic_appointments as child')
                ->joinSub($this->groups(), 'settlement', 'settlement.id', '=', 'child.clinic_visit_group_id')
                ->where('child.status', 'completed')->where('child.paid', true)->whereNotNull('child.consultation_finished_at')
                ->select('child.id', 'settlement.paid_at', 'settlement.amount', 'child.consultation_finished_at'));
        }

        return DB::query()->fromSub($legacy, 'eligible_cases');
    }

    private function groups(): Builder
    {
        return $this->confirmed('clinic_visit_groups', ClinicVisitGroup::class);
    }

    private function confirmed(string $table, string $model): Builder
    {
        $query = DB::table($table.' as c');
        $hasGateway = Schema::hasTable('gateway_payments') && Schema::hasColumn($table, 'gateway_payment_id');
        $cash = fn ($cash) => $cash->where('c.payment_method', 'cash')
            ->whereNotNull('c.paid_at')->where('c.total_amount', '>=', 0);
        if (! $hasGateway) {
            return $cash($query)->select('c.id', 'c.paid_at')->selectRaw('c.total_amount as amount');
        }

        return $query->leftJoin('gateway_payments as g', 'g.id', '=', 'c.gateway_payment_id')
            ->where(function ($query) use ($cash, $model) {
                $query->where(fn ($row) => $cash($row)->whereNull('c.gateway_payment_id'))
                    ->orWhere(fn ($gateway) => $gateway->where('g.provider', 'paymongo')->where('g.context', 'clinic')
                        ->where('g.payable_type', (new $model)->getMorphClass())->whereColumn('g.payable_id', 'c.id')
                        ->where('g.status', 'paid')->where('g.livemode', true)->where('g.currency', 'PHP')
                        ->whereNull('g.reconciliation_reason')->whereNotNull('g.paid_at')->where('g.amount', '>', 0));
            })
            ->select('c.id')
            ->selectRaw('CASE WHEN c.gateway_payment_id IS NULL THEN c.paid_at ELSE g.paid_at END as paid_at')
            ->selectRaw($model === ClinicVisitGroup::class ? 'c.total_amount as amount'
                : 'CASE WHEN c.gateway_payment_id IS NULL THEN c.total_amount ELSE g.amount END as amount');
    }
}
