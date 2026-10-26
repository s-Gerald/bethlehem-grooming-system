<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Services\OperationalCapacity;
use App\Services\RegistrationServiceDay;
use Illuminate\Http\Request;

class RegistrationQueueController extends Controller
{
    public function __invoke(Request $request, RegistrationServiceDay $days, OperationalCapacity $capacity)
    {
        $service = $request->validate(['service' => 'required|in:grooming,clinic'])['service'];
        $day = $days->resolve($service);
        $open = $day === now('Asia/Manila')->toDateString() && $days->acceptingCheckIns($service);
        $waiting = $open ? ($service === 'grooming'
            ? $capacity->groomingPets()->whereNull('grooming_start_time')->where('grooming_state', 'not_started')->count()
            : ClinicAppointment::query()->where('status', 'checked_in')->count()) : null;

        return response()->json([
            'service' => $service,
            'service_day' => $day,
            'accepting_check_ins' => $open,
            'waiting_count' => $waiting,
        ])->header('Cache-Control', 'no-store, private');
    }
}
