<?php

namespace App\Services;

use App\Models\ClinicSetting;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ClinicDailySummaryNotificationService
{
    public function __construct(private DashboardCompletionMetrics $metrics) {}

    public function generateIfDue(): void
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $closeTime = ClinicSetting::current()->serviceAvailability('clinic')['close_time'];
        if ($now->format('H:i') < $closeTime) {
            return;
        }

        $day = $now->startOfDay();
        $summaries = Notification::query()->where('type', Notification::TYPE_CLINIC_DAILY_SUMMARY)
            ->where('created_at', '>=', $day)->where('created_at', '<', $day->addDay());
        if ($summaries->exists()) {
            return;
        }

        DB::transaction(function () use ($now, $day, $summaries): void {
            // Lock the shared settings row before checking for today's notification again.
            $settings = ClinicSetting::current(lockForUpdate: true);
            $closing = $day->setTimeFromTimeString($settings->serviceAvailability('clinic')['close_time']);
            if ($now->lt($closing) || (clone $summaries)->lockForUpdate()->first(['notification_id']) !== null) {
                return;
            }

            $count = $this->metrics->clinicCompletions($day->toDateTimeString(), $closing->toDateTimeString(), includeEnd: true);
            $visits = $count === 1 ? 'clinic visit' : 'clinic visits';

            Notification::create([
                'type' => Notification::TYPE_CLINIC_DAILY_SUMMARY,
                'booking_id' => null,
                'clinic_appointment_id' => null,
                'message' => "{$count} {$visits} completed as of {$closing->format('g:i A')}.",
                'is_read' => false,
                'created_at' => $now,
            ]);
        }, attempts: 3);
    }
}
