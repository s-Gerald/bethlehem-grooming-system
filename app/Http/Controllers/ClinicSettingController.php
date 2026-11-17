<?php

namespace App\Http\Controllers;

use App\Models\ClinicSetting;
use App\Models\ClinicClosure;
use App\Services\DailyPetQueue;
use App\Services\OperationalCapacity;
use App\Services\AvailabilityTimeWindowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicSettingController extends Controller
{
    public function general()
    {
        $settings = ClinicSetting::query()->select(ClinicSetting::PUBLIC_INFORMATION_FIELDS)
            ->findOrFail(ClinicSetting::SINGLETON_ID);

        return response()->json(['data' => $settings->only(ClinicSetting::PUBLIC_INFORMATION_FIELDS)])
            ->header('Cache-Control', 'no-store');
    }

    public function updateGeneral(Request $request)
    {
        $data = $request->validate([
            'primary_contact_number' => ['required', 'string', 'max:50'],
            'secondary_contact_number' => ['required', 'string', 'max:50'],
            'clinic_address' => ['required', 'string', 'max:500'],
            'map_directions_url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'facebook_url' => ['required', 'string', 'url:http,https', 'max:2048'],
        ]);
        $settings = ClinicSetting::query()->findOrFail(ClinicSetting::SINGLETON_ID);
        $settings->update($data);

        return response()->json([
            'message' => 'Public clinic information saved.',
            'data' => $settings->only(ClinicSetting::PUBLIC_INFORMATION_FIELDS),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function page()
    {
        $html = file_get_contents(base_path('resources/views/admin/availability.html'));
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private',
        ]);
    }

    public function availability()
    {
        $settings = ClinicSetting::current();

        return response()->json([
            'success' => true,
            'availability' => $settings->availabilityPayload(),
            'groomers_on_duty' => $settings->groomers_on_duty,
            'pet_groomers' => \App\Models\PetGroomer::orderBy('id')->get(),
            'stopped_today' => ClinicClosure::stoppedToday(),
            'blocked_dates' => ClinicClosure::blockedDates(),
            'capacity' => app(OperationalCapacity::class)->snapshot($settings),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function updateCapacity(Request $request)
    {
        $data = $request->validate([
            'maximum' => ['required', 'integer', 'min:1', 'max:'.ClinicSetting::MAX_CAGE_CAPACITY, 'regex:/^[1-9][0-9]*$/'],
        ]);

        return app(DailyPetQueue::class)->runForDate(now('Asia/Manila')->toDateString(), function () use ($data) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $settings->fill(['grooming_cage_capacity' => (int) $data['maximum']]);
            $capacity = app(OperationalCapacity::class)->snapshot($settings);
            if ($capacity['max'] < $capacity['used']) {
                throw ValidationException::withMessages(['maximum' => 'Capacity cannot be below the number of pets currently on site.']);
            }
            $settings->save();

            return response()->json(['success' => true, 'message' => 'Capacity saved.', 'capacity' => $capacity]);
        });
    }

    public function updateAvailability(
        Request $request,
        AvailabilityTimeWindowService $timeWindows,
    )
    {
        $data = $request->validate([
            'service' => ['required', Rule::in(['clinic', 'grooming'])],
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i'],
        ]);

        $openTime = Carbon::createFromFormat('H:i', $data['open_time']);
        $closeTime = Carbon::createFromFormat('H:i', $data['close_time']);

        if ($closeTime->lessThanOrEqualTo($openTime)) {
            throw ValidationException::withMessages([
                'close_time' => 'Closing time must be later than opening time.',
            ]);
        }

        $service = $data['service'];
        $settings = DB::transaction(function () use ($data, $service, $timeWindows) {
            $settings = ClinicSetting::current(lockForUpdate: true);
            $previous = $settings->serviceAvailability($service);
            if ($data['open_time'] > $previous['open_time'] || $data['close_time'] < $previous['close_time']) {
                $table = $service === 'grooming' ? 'bookings' : 'clinic_appointments';
                $date = $service === 'grooming' ? 'booking_date' : 'appointment_date';
                $checkIn = $service === 'grooming' ? 'dropped_off_at' : 'checked_in_at';
                // Retain the interruption even if staff later restores the original hours.
                if (Schema::hasColumn($table, 'registration_no_show_exempt_at')) {
                    $now = now('Asia/Manila');
                    DB::table($table)->where('status', 'waiting_to_arrive')->whereNull($checkIn)
                        ->whereNull('registration_no_show_exempt_at')
                        ->whereBetween($date, [$now->toDateString(), $now->copy()->addDay()->toDateString()])
                        ->update(['registration_no_show_exempt_at' => $now]);
                }
            }
            $settings->update([
                "{$service}_open_time" => $data['open_time'],
                "{$service}_close_time" => $data['close_time'],
            ]);
            $settings = $settings->fresh();
            $timeWindows->sync($settings);

            return $settings;
        });

        return response()->json([
            'success' => true,
            'message' => ucfirst($service).' availability updated.',
            'availability' => $settings->availabilityPayload(),
        ]);
    }

    public function updateGroomersOnDuty(Request $request)
    {
        throw ValidationException::withMessages([
            'groomers_on_duty' => 'Update Pet Groomer duty states in Availability settings.',
        ]);
    }
}
