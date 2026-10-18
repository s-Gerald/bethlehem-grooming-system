<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

class ReportPeriod
{
    public function range(array $filters): array
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

    public function between(Builder $query, string $column, CarbonImmutable $start, CarbonImmutable $until): void
    {
        $query->whereRaw("$column >= ? AND $column < ?", [$start->toDateTimeString(), $until->toDateTimeString()]);
    }

    public function grouping(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $days = $start->diffInDays($end->addDay());
        return $days <= 45 ? 'day' : ($days <= 180 ? 'week' : 'month');
    }

    public function series(CarbonImmutable $start, CarbonImmutable $end, string $grouping, $collections, $visits): array
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
