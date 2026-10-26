<?php

namespace App\Services;

use App\Models\ClinicSetting;
use App\Models\ClinicClosure;
use App\Models\TimeWindow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvailabilityTimeWindowService
{
    public const DEFAULT_MAX_SLOTS = 4;

    public function sync(ClinicSetting $settings): void
    {
        DB::transaction(function () use ($settings): void {
            $desiredWindows = collect(['clinic', 'grooming'])
                ->flatMap(fn (string $service) => $settings->desiredTimeWindows($service))
                ->unique(fn (array $window) => $this->windowKey(
                    $window['start_time'],
                    $window['end_time'],
                ))
                ->values();

            $existingWindows = TimeWindow::query()
                ->orderBy('window_id')
                ->lockForUpdate()
                ->get();
            $activeWindowIds = [];

            foreach ($desiredWindows as $desiredWindow) {
                $window = $existingWindows->first(
                    fn (TimeWindow $candidate) => $this->windowKey(
                        $candidate->start_time,
                        $candidate->end_time,
                    ) === $this->windowKey(
                        $desiredWindow['start_time'],
                        $desiredWindow['end_time'],
                    ),
                );

                if (! $window) {
                    $window = TimeWindow::create([
                        ...$desiredWindow,
                        'max_slots' => self::DEFAULT_MAX_SLOTS,
                        'is_active' => true,
                    ]);
                    $existingWindows->push($window);
                } else {
                    $window->update([
                        'window_label' => $desiredWindow['window_label'],
                        'is_active' => true,
                    ]);
                }

                $activeWindowIds[] = $window->window_id;
            }

            $windowsToDeactivate = TimeWindow::query();

            if ($activeWindowIds !== []) {
                $windowsToDeactivate->whereNotIn('window_id', $activeWindowIds);
            }

            $windowsToDeactivate->update(['is_active' => false]);
        });
    }

    /**
     * @return Collection<int, TimeWindow>
     */
    public function availableWindows(ClinicSetting $settings, string $service): Collection
    {
        $allowedKeys = collect($settings->desiredTimeWindows($service))
            ->mapWithKeys(fn (array $window) => [
                $this->windowKey($window['start_time'], $window['end_time']) => true,
            ]);

        return TimeWindow::query()
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->filter(fn (TimeWindow $window) => $allowedKeys->has(
                $this->windowKey($window->start_time, $window->end_time),
            ))
            ->values();
    }

    private function windowKey(string $startTime, string $endTime): string
    {
        return substr($startTime, 0, 8).'-'.substr($endTime, 0, 8);
    }

    public function isClosed(string $date, string $service): bool
    {
        return ClinicClosure::query()->forService($service)->where('is_active', true)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->whereIn('type', ['blocked_date', 'stop_today'])->exists();
    }

    public function preferredWindows(ClinicSetting $settings, string $service, string $date): Collection
    {
        $closed = $this->isClosed($date, $service);

        return $this->availableWindows($settings, $service)->map(fn (TimeWindow $window) => [
            'window_id' => $window->window_id,
            'window_label' => $window->displayLabel(),
            'start_time' => $window->start_time,
            'end_time' => $window->end_time,
            'is_past' => $this->windowHasEnded($date, $window),
            'is_closed' => $closed,
        ]);
    }

    public function assertPreferredArrival(ClinicSetting $settings, string $service, string $date, TimeWindow $window): void
    {
        if ($this->isClosed($date, $service)
            || ! $window->is_active
            || ! $settings->isWindowWithinOperatingHours($service, $window->start_time, $window->end_time)) {
            throw ValidationException::withMessages([
                'window_id' => 'This preferred arrival window is unavailable. Please choose another date or time.',
            ]);
        }

        $this->assertWindowHasNotEnded($date, $window);
    }

    private function windowHasEnded(string $date, TimeWindow $window): bool
    {
        return $date.' '.substr($window->end_time, 0, 8) <= now()->format('Y-m-d H:i:s');
    }

    public function assertWindowHasNotEnded(string $date, TimeWindow $window): void
    {
        if (! $this->windowHasEnded($date, $window)) {
            return;
        }

        $message = 'That arrival window just ended. Please choose the next available time.';
        $exception = ValidationException::withMessages(['window_id' => $message]);
        $exception->response = response()->json([
            'success' => false,
            'code' => 'arrival_window_ended',
            'message' => $message,
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
    }
}
