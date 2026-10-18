<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GroomingPerformanceReport
{
    // Grooming workflows persist datetimes in the application's Manila timezone.
    private const FINISHED_AT = 'COALESCE(bp.grooming_end_time, b.grooming_finished_at)';

    public function build(array $filters): array
    {
        [$start, $end] = $this->range($filters);
        $until = $end->addDay();
        $grouping = $start->diffInDays($until) <= 45 ? 'day' : ($start->diffInDays($until) <= 180 ? 'week' : 'month');
        $payments = $this->collections();
        $this->between($payments, 'paid_at', $start, $until);
        $money = (clone $payments)->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(DISTINCT booking_id) as bookings')->first();

        $pets = $this->completedPets();
        $this->between($pets, self::FINISHED_AT, $start, $until);
        $visits = (clone $pets)->count();
        $customers = $this->customers($pets, $until);
        $collectionsByDay = (clone $payments)->selectRaw('DATE(paid_at) as day, SUM(amount) as total')->groupByRaw('DATE(paid_at)')->get();
        $datedPets = (clone $pets)->selectRaw('DATE('.self::FINISHED_AT.') as day');
        $visitsByDay = DB::query()->fromSub($datedPets, 'completed_days')
            ->selectRaw('day, COUNT(*) as total')->groupBy('day')->get();

        return [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(),
                'timezone' => 'Asia/Manila', 'grouping' => $grouping],
            'overview' => ['collections' => round((float) $money->total, 2), 'completedVisits' => $visits,
                'paidBookings' => (int) $money->bookings,
                'averageSale' => $money->bookings ? round($money->total / $money->bookings, 2) : 0,
                'returningCustomers' => $customers['returning']],
            'performance' => $this->series($start, $end, $grouping, $collectionsByDay, $visitsByDay),
            'services' => $this->services($pets),
            'customers' => $customers,
            'outcomes' => $this->outcomes($start, $until),
        ];
    }

    private function range(array $filters): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        return match ($filters['period'] ?? 'this_month') {
            'today' => [$today, $today],
            'last_7_days' => [$today->subDays(6), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom' => [CarbonImmutable::parse($filters['start'], 'Asia/Manila')->startOfDay(),
                CarbonImmutable::parse($filters['end'], 'Asia/Manila')->startOfDay()],
            default => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
        };
    }

    private function between(Builder $query, string $column, CarbonImmutable $start, CarbonImmutable $until): void
    {
        $query->whereRaw("$column >= ? AND $column < ?", [$start->toDateTimeString(), $until->toDateTimeString()]);
    }

    private function completedPets(): Builder
    {
        return DB::table('booking_pets as bp')->join('bookings as b', 'b.booking_id', '=', 'bp.booking_id')
            ->whereNotIn('b.status', ['cancelled', 'no_show', 'expired'])
            ->whereNull('bp.cancelled_at')
            ->where(function ($q) {
                $q->where('bp.grooming_state', 'finished')
                    ->orWhere(fn ($legacy) => $legacy->whereNull('bp.grooming_state')->whereNotNull('bp.grooming_end_time'));
            })->whereRaw(self::FINISHED_AT.' IS NOT NULL');
    }

    private function collections(): Builder
    {
        $key = (new Payment)->getKeyName();
        $eligible = DB::table('payments as p')->join('bookings as b', 'b.booking_id', '=', 'p.booking_id')
            ->where('p.payment_status', 'paid')->whereNotNull('p.paid_at')
            ->whereNotIn('b.status', ['cancelled', 'no_show', 'expired']);
        $hasGateway = Schema::hasTable('gateway_payments') && Schema::hasColumn('payments', 'gateway_payment_id');
        if ($hasGateway) {
            $eligible->leftJoin('gateway_payments as g', 'g.id', '=', 'p.gateway_payment_id')
                ->where(function ($q) {
                    $q->where(fn ($offline) => $offline->whereNull('p.gateway_payment_id')
                        ->where(fn ($provider) => $provider->whereNull('p.payment_provider')->orWhere('p.payment_provider', '!=', 'paymongo')))
                        ->orWhere(fn ($online) => $online->where('g.livemode', true)->where('g.status', 'paid')
                            ->where('g.context', 'grooming')->whereColumn('g.payable_id', 'p.booking_id'));
                });
        }
        // The settlement workflow pays a booking once. Deduplicate legacy retry rows
        // before filtering dates so a later duplicate cannot become a second collection.
        $first = (clone $eligible)->selectRaw("MIN(p.$key) as payment_key")->groupBy('p.booking_id');
        $selected = DB::table('payments as p')->joinSub($first, 'first_payment', 'first_payment.payment_key', '=', "p.$key");
        $amount = 'p.total_amount';
        if (Schema::hasTable('grooming_payment_products')) {
            $products = DB::table('grooming_payment_products')->selectRaw('payment_id, SUM(subtotal) as total')->groupBy('payment_id');
            $selected->leftJoinSub($products, 'products', 'products.payment_id', '=', "p.$key");
            $amount = '(p.total_amount - COALESCE(products.total, 0))';
        }
        if ($hasGateway) {
            $selected->leftJoin('gateway_payments as g', 'g.id', '=', 'p.gateway_payment_id');
            $frozenTotal = DB::getDriverName() === 'sqlite'
                ? "json_extract(g.snapshot, '$.grooming_total')"
                : "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(g.snapshot, '$.grooming_total')), 'null')";
            $snapshot = DB::getDriverName() === 'sqlite'
                ? "json_extract(g.snapshot, '$.summary.final_booking_total')"
                : "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(g.snapshot, '$.summary.final_booking_total')), 'null')";
            // Frozen grooming totals also cover online product-stock reconciliation failures.
            $amount = "COALESCE(CAST($frozenTotal AS DECIMAL(12,2)), CAST($snapshot AS DECIMAL(12,2)), $amount)";
        }
        return DB::query()->fromSub($selected->select('p.booking_id', 'p.paid_at')
            ->selectRaw("CASE WHEN $amount > 0 THEN $amount ELSE 0 END as amount"), 'collections');
    }

    private function services(Builder $pets): array
    {
        $query = (clone $pets)->join('booking_services as bs', function ($join) {
            $join->on('bs.booking_pet_id', '=', 'bp.booking_pet_id')->on('bs.booking_id', '=', 'bp.booking_id');
        })->leftJoin('services as s', 's.service_id', '=', 'bs.service_id')
            ->leftJoin('addons as a', 'a.addon_id', '=', 'bs.addon_id');
        $name = "COALESCE(bs.service_name_snapshot, s.service_name, a.addon_name, 'Historical service')";
        $kind = "COALESCE(bs.service_kind_snapshot, CASE WHEN bs.addon_id IS NOT NULL THEN 'addon' ELSE s.kind END, 'service')";
        $lines = $query->select('bs.booking_service_id')->selectRaw("$name as name, $kind as kind");
        return DB::query()->fromSub($lines, 'completed_services')
            ->selectRaw('name, kind, COUNT(DISTINCT booking_service_id) as completed')
            ->groupBy('name', 'kind')->orderByDesc('completed')->orderBy('name')->get()
            ->map(fn ($row) => ['name' => $row->name, 'kind' => $row->kind, 'count' => (int) $row->completed])->all();
    }

    private function customers(Builder $periodPets, CarbonImmutable $until): array
    {
        // Registered and unregistered walk-ins retain a stable owner across visits.
        $concat = DB::getDriverName() === 'sqlite'
            ? fn ($prefix, $column) => "'$prefix' || $column"
            : fn ($prefix, $column) => "CONCAT('$prefix', $column)";
        $identity = 'CASE WHEN COALESCE(b.user_id, w.user_id) IS NOT NULL THEN '.$concat('user:', 'COALESCE(b.user_id, w.user_id)').
            ' WHEN w.unregistered_customer_id IS NOT NULL THEN '.$concat('unregistered:', 'w.unregistered_customer_id').
            " WHEN NULLIF(TRIM(w.phone), '') IS NOT NULL THEN ".$concat('phone:', "TRIM(w.phone)").
            ' WHEN b.walkin_id IS NOT NULL THEN '.$concat('walkin:', 'b.walkin_id').' END';
        $visits = $this->completedPets()->leftJoin('walkins as w', 'w.id', '=', 'b.walkin_id')
            ->whereRaw(self::FINISHED_AT.' < ?', [$until->toDateTimeString()])
            ->select('b.booking_id')->selectRaw("$identity as customer_key");
        // Group named derived columns so strict MySQL/MariaDB does not need to
        // infer equivalence between repeated CASE/COALESCE expressions.
        $history = DB::query()->fromSub($visits, 'customer_visits')
            ->selectRaw('customer_key, COUNT(DISTINCT booking_id) as visits')->groupBy('customer_key');
        $active = (clone $periodPets)->leftJoin('walkins as w', 'w.id', '=', 'b.walkin_id')
            ->selectRaw("DISTINCT $identity as customer_key");
        $counts = DB::query()->fromSub($history, 'history')->joinSub($active, 'active', 'history.customer_key', '=', 'active.customer_key')
            ->selectRaw('SUM(CASE WHEN visits = 1 THEN 1 ELSE 0 END) as new_customers, SUM(CASE WHEN visits > 1 THEN 1 ELSE 0 END) as returning_customers')->first();
        return ['new' => (int) $counts->new_customers, 'returning' => (int) $counts->returning_customers];
    }

    private function outcomes(CarbonImmutable $start, CarbonImmutable $until): array
    {
        $completed = $this->completedPets()->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('booking_pets as unfinished')->whereColumn('unfinished.booking_id', 'b.booking_id')
                ->whereNull('unfinished.cancelled_at')->where(fn ($state) => $state->whereNull('unfinished.grooming_state')->orWhere('unfinished.grooming_state', '!=', 'cancelled'))
                ->where(function ($q) {
                    $q->whereIn('unfinished.grooming_state', ['not_started', 'in_progress'])
                        ->orWhere(fn ($legacy) => $legacy->whereNull('unfinished.grooming_end_time')->whereNull('b.grooming_finished_at'));
                });
        })->select('b.booking_id')->selectRaw('MAX('.self::FINISHED_AT.') as completed_at')->groupBy('b.booking_id');
        $completed = DB::query()->fromSub($completed, 'finished_bookings');
        $this->between($completed, 'completed_at', $start, $until);
        $cancelled = DB::table('bookings')->where('status', 'cancelled');
        $cancelled->whereRaw('DATE(COALESCE(cancelled_at, booking_date)) >= ? AND DATE(COALESCE(cancelled_at, booking_date)) < ?',
            [$start->toDateString(), $until->toDateString()]);
        $noShow = DB::table('bookings')->where('status', 'no_show')
            ->where('booking_date', '>=', $start->toDateString())->where('booking_date', '<', $until->toDateString());
        return ['completed' => $completed->count(), 'cancelled' => $cancelled->count(), 'noShow' => $noShow->count()];
    }

    private function series(CarbonImmutable $start, CarbonImmutable $end, string $grouping, $collections, $visits): array
    {
        $bucket = fn (CarbonImmutable $date) => match ($grouping) {
            'week' => $date->startOfWeek(), 'month' => $date->startOfMonth(), default => $date,
        };
        $points = [];
        for ($date = $bucket($start); $date->lte($end); $date = match ($grouping) {
            'week' => $date->addWeek(), 'month' => $date->addMonth(), default => $date->addDay(),
        }) {
            $last = match ($grouping) {'week' => $date->addDays(6), 'month' => $date->endOfMonth()->startOfDay(), default => $date};
            $points[$date->toDateString()] = ['date' => $date->max($start)->toDateString(), 'end' => $last->min($end)->toDateString(),
                'collections' => 0, 'visits' => 0];
        }
        foreach (['collections' => $collections, 'visits' => $visits] as $metric => $rows) {
            foreach ($rows as $row) {
                $key = $bucket(CarbonImmutable::parse($row->day, 'Asia/Manila'))->toDateString();
                $points[$key][$metric] += $metric === 'visits' ? (int) $row->total : round((float) $row->total, 2);
            }
        }
        return array_values($points);
    }
}
