<?php

namespace App\Services;

use App\Models\ClinicClosure;
use App\Models\ClinicSetting;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class RegistrationServiceDay
{
    public function resolve(string $service): string
    {
        $now = Carbon::now('Asia/Manila');
        $hours = ClinicSetting::current()->serviceAvailability($service);
        if ($hours['open_time'] >= $hours['close_time']) {
            throw ValidationException::withMessages(['service_day' => 'Registration is currently unavailable for this service.']);
        }

        $day = $now->copy()->startOfDay();
        if ($now->format('H:i') >= $hours['close_time']) $day->addDay();

        // One query; jump over closures instead of querying every closed day.
        $closures = ClinicClosure::query()->forService($service)->where('is_active', true)
            ->whereDate('end_date', '>=', $day->toDateString())
            ->whereIn('type', ['blocked_date', 'stop_today'])->orderBy('start_date')->get();
        foreach ($closures as $closure) {
            if ($closure->start_date->toDateString() <= $day->toDateString()
                && $closure->end_date->toDateString() >= $day->toDateString()) {
                $day = $closure->end_date->copy()->addDay();
            }
        }

        return $day->toDateString();
    }

    public function acceptingCheckIns(string $service): bool
    {
        $now = Carbon::now('Asia/Manila');
        return ClinicSetting::current()->isWithinOperatingHours($service, $now)
            && ! ($now->format('H:i') >= '12:00' && $now->format('H:i') < '13:00')
            && ! app(AvailabilityTimeWindowService::class)->isClosed($now->toDateString(), $service);
    }
}
