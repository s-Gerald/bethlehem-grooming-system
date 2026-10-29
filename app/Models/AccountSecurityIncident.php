<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountSecurityIncident extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'first_occurred_at' => 'datetime', 'last_occurred_at' => 'datetime',
        'reviewed_at' => 'datetime', 'alert_attempted_at' => 'datetime', 'failure_count' => 'integer',
    ];

    public function payload(): array
    {
        return ['id' => $this->getKey(), 'title' => 'Unusual sign-in activity',
            'type' => $this->type, 'severity' => $this->severity,
            'first_occurred_at' => $this->first_occurred_at->toIso8601String(),
            'last_occurred_at' => $this->last_occurred_at->toIso8601String(),
            'failure_count' => $this->failure_count,
            'status' => $this->reviewed_at ? 'Reviewed' : 'Needs review',
            'reviewed_at' => $this->reviewed_at?->toIso8601String()];
    }

    public static function pruneExpired(int $limit = 200): int
    {
        $ids = static::where('last_occurred_at', '<=', now()->subDays(max(1, (int) config('authentication.security.retention_days', 90))))
            ->orderBy('last_occurred_at')->limit($limit)->pluck('id');
        return static::whereKey($ids)->delete();
    }
}
