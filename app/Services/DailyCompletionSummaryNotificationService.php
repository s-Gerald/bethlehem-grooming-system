<?php

namespace App\Services;

use App\Models\ClinicSetting;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DailyCompletionSummaryNotificationService
{
    public function __construct(private DashboardCompletionMetrics $metrics) {}

    public function generateIfDue(): void
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $day = $now->startOfDay();
        if ($this->pendingTypes($now, ...$this->closingTimes($day, ClinicSetting::current())) === []) {
            return;
        }

        DB::transaction(function () use ($now, $day): void {
            // Lock the shared settings row before checking for today's summaries again.
            $settings = ClinicSetting::current(lockForUpdate: true);
            [$clinicClosing, $groomingClosing] = $this->closingTimes($day, $settings);
            $pending = $this->pendingTypes($now, $clinicClosing, $groomingClosing, lockForUpdate: true);
            if ($pending === []) {
                return;
            }

            $start = $day->toDateTimeString();
            $clinic = $this->metrics->clinicCompletions($start, $clinicClosing->toDateTimeString(), includeEnd: true);
            foreach ($pending as $type) {
                if ($type === Notification::TYPE_CLINIC_DAILY_SUMMARY) {
                    $visits = $clinic === 1 ? 'clinic visit' : 'clinic visits';
                    $message = "{$clinic} {$visits} completed today.";
                } else {
                    $grooming = $this->metrics->groomingCompletions($start, $groomingClosing->toDateTimeString(), includeEnd: true);
                    $total = $clinic + $grooming;
                    $sessions = $total === 1 ? 'session' : 'sessions';
                    $message = "{$total} {$sessions} completed today — {$grooming} grooming, {$clinic} clinic.";
                }

                Notification::create([
                    'type' => $type,
                    'booking_id' => null,
                    'clinic_appointment_id' => null,
                    'message' => $message,
                    'is_read' => false,
                    'created_at' => $now,
                ]);
            }
        }, attempts: 3);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function closingTimes(CarbonImmutable $day, ClinicSetting $settings): array
    {
        return [
            $day->setTimeFromTimeString($settings->serviceAvailability('clinic')['close_time']),
            $day->setTimeFromTimeString($settings->serviceAvailability('grooming')['close_time']),
        ];
    }

    private function pendingTypes(
        CarbonImmutable $now,
        CarbonImmutable $clinicClosing,
        CarbonImmutable $groomingClosing,
        bool $lockForUpdate = false,
    ): array {
        if ($now->lt($clinicClosing)) {
            return [];
        }

        $types = [Notification::TYPE_CLINIC_DAILY_SUMMARY];
        if ($now->gte($groomingClosing)) {
            $types[] = Notification::TYPE_DAILY_COMPLETION_SUMMARY;
        }

        $day = $now->startOfDay();
        $existing = Notification::query()->whereIn('type', $types)
            ->where('created_at', '>=', $day)->where('created_at', '<', $day->addDay())
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->pluck('type')->all();

        return array_diff($types, $existing);
    }
}
