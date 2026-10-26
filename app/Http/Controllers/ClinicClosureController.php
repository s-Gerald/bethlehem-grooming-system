<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\ClinicClosure;
use App\Models\ClinicSetting;
use App\Models\Booking;

class ClinicClosureController extends Controller
{
    // Public scheduling information; privileged staff data is served separately.
    public function status()
    {
        return response()->json([
            'success' => true,
            'stopped_today' => ClinicClosure::stoppedToday(),
            'blocked_dates' => ClinicClosure::blockedDates(upcomingOnly: true),
            'availability' => ClinicSetting::current()->availabilityPayload(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function stopToday(Request $request)
    {
        return $this->changeIntake($request, true);
    }

    public function reopenToday(Request $request)
    {
        return $this->changeIntake($request, false);
    }

    private function changeIntake(Request $request, bool $stop)
    {
        $service = $request->validate(['service' => ['required', \Illuminate\Validation\Rule::in(['clinic', 'grooming'])]])['service'];
        $today = now('Asia/Manila')->toDateString();
        return app(\App\Services\DailyPetQueue::class)->runForDate($today, function () use ($request, $today, $service, $stop) {
            $active = ClinicClosure::where('type', 'stop_today')->forService($service)
                ->whereDate('start_date', $today)->where('is_active', true)->lockForUpdate()->get();
            if ($stop && $active->isEmpty()) {
                ClinicClosure::create([
                    'type' => 'stop_today', 'service' => $service, 'start_date' => $today,
                    'end_date' => $today, 'is_active' => true, 'created_by' => $request->user()->user_id,
                ]);
            } elseif (! $stop) {
                $other = $service === 'clinic' ? 'grooming' : 'clinic';
                foreach ($active as $closure) {
                    // Preserve the legacy record and original attribution for the remaining stop.
                    if ($closure->service === null && ! ClinicClosure::where('type', 'stop_today')
                        ->where('service', $other)->whereDate('start_date', $today)->where('is_active', true)->exists()) {
                        $remaining = $closure->replicate();
                        $remaining->service = $other;
                        $remaining->save();
                    }
                    $closure->update(['is_active' => false]);
                }
            }
            return response()->json([
                'success' => true,
                'message' => ucfirst($service).($stop ? ' intake stopped for today.' : ' intake reopened.'),
                'stopped_today' => ClinicClosure::stoppedToday(),
            ]);
        });
    }

    // ── LIST BLOCKED DATES ────────────────────────────────
    // GET /api/admin/clinic/blocked-dates
    public function blockedDates()
    {
        $dates = ClinicClosure::blockedDates();

        return response()->json(['success' => true, 'blocked_dates' => $dates]);
    }

    // ── ADD BLOCKED DATE ──────────────────────────────────
    // POST /api/admin/clinic/blocked-dates
    public function addBlockedDate(Request $request)
    {
        $today = now()->toDateString();

        $request->validate([
            'start_date' => 'required|date|after_or_equal:' . $today,
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'nullable|string|max:255',
        ]);

        $start = Carbon::parse($request->start_date);
        $end   = Carbon::parse($request->end_date);

        // Check for existing bookings in this range
        $conflictCount = Booking::whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', ['cancelled', 'no_show', 'expired', 'archived'])
            ->count();

        ClinicClosure::create([
            'type'       => 'blocked_date',
            'start_date' => $start->toDateString(),
            'end_date'   => $end->toDateString(),
            'reason'     => $request->reason,
            'is_active'  => 1,
            'created_by' => $request->user()->user_id,
        ]);

        return response()->json([
            'success'        => true,
            'message'        => 'Date(s) blocked successfully.',
            'conflict_count' => $conflictCount,
            'conflict_warning' => $conflictCount > 0
                ? "{$conflictCount} existing booking(s) found in this date range. Please contact those customers to cancel or reschedule."
                : null,
        ], 201);
    }

    // ── REMOVE BLOCKED DATE ───────────────────────────────
    // DELETE /api/admin/clinic/blocked-dates/{id}
    public function removeBlockedDate(Request $request, $id)
    {
        $closure = ClinicClosure::where('id', $id)->where('type', 'blocked_date')->first();

        if (!$closure) {
            return response()->json(['success' => false, 'message' => 'Blocked date not found.'], 404);
        }

        $closure->update(['is_active' => 0]);

        return response()->json(['success' => true, 'message' => 'Blocked date removed.']);
    }
}
