<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ClinicPerformanceReport
{
    public function __construct(private readonly ReportPeriod $period, private readonly ClinicCollections $collections) {}

    public function build(array $filters): array
    {
        [$start, $end] = $this->period->range($filters);
        $until = $end->addDay();
        $grouping = $this->period->grouping($start, $end);
        $payments = $this->collections->settlements(requireFinishedVisit: true);
        $this->period->between($payments, 'paid_at', $start, $until);
        $money = (clone $payments)->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as cases')->first();
        $visits = $this->collections->visits();
        $this->period->between($visits, 'consultation_finished_at', $start, $until);
        $completed = (clone $visits)->count();

        return [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(),
                'timezone' => 'Asia/Manila', 'grouping' => $grouping],
            'overview' => ['collections' => round((float) $money->total, 2), 'completedVisits' => $completed,
                'paidCases' => (int) $money->cases, 'averageSale' => $money->cases ? round($money->total / $money->cases, 2) : 0],
            'performance' => $this->period->series($start, $end, $grouping,
                $this->byDay($payments, 'paid_at', 'SUM(amount)'), $this->byDay($visits, 'consultation_finished_at', 'COUNT(*)')),
            'services' => $this->services($visits),
            'outcomes' => $this->outcomes($start, $until, $completed),
        ];
    }

    private function byDay(Builder $query, string $column, string $aggregate)
    {
        $days = (clone $query)->selectRaw("DATE($column) as day, amount");
        return DB::query()->fromSub($days, 'dated_cases')->selectRaw("day, $aggregate as total")->groupBy('day')->get();
    }

    private function services(Builder $visits): array
    {
        $snapshot = DB::getDriverName() === 'sqlite'
            ? "json_extract(cs.service_snapshot, '$.name')"
            : "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cs.service_snapshot, '$.name')), 'null')";
        $lines = (clone $visits)->join('clinic_case_services as cs', 'cs.clinic_appointment_id', '=', 'eligible_cases.id')
            ->leftJoin('clinic_services as s', 's.id', '=', 'cs.clinic_service_id')
            ->where('cs.status', 'performed')->whereNotNull('cs.performed_at')->whereNull('cs.cancelled_at')
            ->select('cs.id')->selectRaw("COALESCE(NULLIF($snapshot, ''), s.name, 'Historical service') as name");
        // Group named derived columns for MariaDB ONLY_FULL_GROUP_BY compatibility.
        return DB::query()->fromSub($lines, 'performed_services')
            ->selectRaw('name, COUNT(DISTINCT id) as performed')->groupBy('name')->orderByDesc('performed')->orderBy('name')->get()
            ->map(fn ($row) => ['name' => $row->name, 'kind' => 'service', 'count' => (int) $row->performed])->all();
    }

    private function outcomes(CarbonImmutable $start, CarbonImmutable $until, int $completed): array
    {
        // Cancellation has no dedicated timestamp in the current schema. No-show
        // belongs to its scheduled date; expired pre-registrations are not no-shows.
        $cancelled = DB::table('clinic_appointments')->where('status', 'cancelled');
        $this->period->between($cancelled, 'updated_at', $start, $until);
        $noShow = DB::table('clinic_appointments')->where('status', 'no_show')
            ->where('appointment_date', '>=', $start->toDateString())->where('appointment_date', '<', $until->toDateString());
        return ['completed' => $completed, 'cancelled' => $cancelled->count(), 'noShow' => $noShow->count()];
    }
}
