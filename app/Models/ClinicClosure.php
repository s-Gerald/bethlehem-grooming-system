<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicClosure extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $table = 'clinic_closures';

    protected $fillable = [
        'type',
        'service',
        'start_date',
        'end_date',
        'reason',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    public function scopeForService(\Illuminate\Database\Eloquent\Builder $query, string $service): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($scope) => $scope->whereNull('service')->orWhere('service', $service));
    }

    public static function stoppedToday(): array
    {
        $services = static::where('type', 'stop_today')->where('is_active', true)
            ->whereDate('start_date', now('Asia/Manila')->toDateString())->pluck('service');

        return [
            'clinic' => $services->contains(null) || $services->contains('clinic'),
            'grooming' => $services->contains(null) || $services->contains('grooming'),
        ];
    }

    public static function blockedDates(bool $upcomingOnly = false): \Illuminate\Support\Collection
    {
        return static::where('type', 'blocked_date')->where('is_active', true)
            ->when($upcomingOnly, fn ($query) => $query->where('end_date', '>=', now('Asia/Manila')->toDateString()))
            ->orderBy('start_date')->get()->map(fn (self $closure) => [
                'id' => $closure->id,
                'start_date' => $closure->start_date->toDateString(),
                'end_date' => $closure->end_date->toDateString(),
                'reason' => $closure->reason,
                'created_at' => $closure->created_at,
            ]);
    }
}
