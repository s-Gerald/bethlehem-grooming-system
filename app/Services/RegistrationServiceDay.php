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
        $availability = $this->availability($service);
        if (! $availability['registration_available']) {
            throw ValidationException::withMessages(['service_day' => $availability['message']]);
        }

        return $availability['service_day'];
    }

    public function availability(string $service): array
    {
        $now = Carbon::now('Asia/Manila');
        $hours = ClinicSetting::current()->serviceAvailability($service);
        $today = $now->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();
        $closures = ClinicClosure::query()->forService($service)->where('is_active', true)
            ->where('end_date', '>=', $today)->where('start_date', '<=', $tomorrow)
            ->whereIn('type', ['blocked_date', 'stop_today'])->get(['start_date', 'end_date']);
        $day = null;
        if ($hours['open_time'] < $hours['close_time']) {
            foreach ([$today, $tomorrow] as $candidate) {
                if ($candidate === $today && $now->format('H:i') >= $hours['close_time']) continue;
                if (! $closures->contains(fn ($closure) => $closure->start_date->toDateString() <= $candidate
                    && $closure->end_date->toDateString() >= $candidate)) {
                    $day = $candidate;
                    break;
                }
            }
        }

        return [
            'service' => $service,
            'service_day' => $day,
            'registration_available' => $day !== null,
            'accepting_check_ins' => $day === $today && $this->withinCheckInHours($hours, $now),
            'visit_day_label' => $day ? ($day === $today ? 'Today' : 'Tomorrow').', '.Carbon::parse($day, 'Asia/Manila')->format('D, M j, Y') : null,
            'open_time' => $hours['open_time'],
            'close_time' => $hours['close_time'],
            'check_in_starts' => Carbon::createFromFormat('H:i', $hours['open_time'], 'Asia/Manila')->format('g:i A'),
            'message' => $day ? 'Online Registration available' : ucfirst($service).' registration is unavailable for today and tomorrow.',
        ];
    }

    public function closingDeadline(string $service, string $day): Carbon
    {
        $hours = ClinicSetting::current()->serviceAvailability($service);
        return Carbon::parse($day.' '.$hours['close_time'], 'Asia/Manila');
    }

    public function assertReviewedDay(string $service, ?string $reviewedDay): string
    {
        $availability = $this->availability($service);
        if ($availability['registration_available'] && $reviewedDay === $availability['service_day']) {
            return $reviewedDay;
        }
        $message = $availability['registration_available']
            ? 'The visit day changed. Please review the new date before submitting.' : $availability['message'];
        $exception = ValidationException::withMessages(['service_day' => $message]);
        $exception->response = response()->json([
            'success' => false,
            'code' => $availability['registration_available'] ? 'service_day_changed' : 'service_unavailable',
            'message' => $message,
            'availability' => $availability,
            'errors' => $exception->errors(),
        ], 409);
        throw $exception;
    }

    public function acceptingCheckIns(string $service): bool
    {
        $now = Carbon::now('Asia/Manila');
        return $this->withinCheckInHours(ClinicSetting::current()->serviceAvailability($service), $now)
            && ! app(AvailabilityTimeWindowService::class)->isClosed($now->toDateString(), $service);
    }

    private function withinCheckInHours(array $hours, Carbon $now): bool
    {
        $time = $now->format('H:i');
        return $time >= $hours['open_time'] && $time < $hours['close_time'];
    }
}
