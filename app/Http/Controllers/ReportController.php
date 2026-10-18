<?php

namespace App\Http\Controllers;

use App\Services\GroomingPerformanceReport;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function grooming(Request $request, GroomingPerformanceReport $report)
    {
        if ($request->user()?->role !== 'admin') {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.',
            ], 403));
        }

        $data = $request->validate([
            'period' => 'sometimes|in:today,last_7_days,this_month,last_month,custom',
            'start' => 'required_if:period,custom|nullable|date_format:Y-m-d',
            'end' => 'required_if:period,custom|nullable|date_format:Y-m-d|after_or_equal:start',
        ]);

        try {
            $result = $report->build($data);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load reports. Please try again.',
            ], 500)->header('Cache-Control', 'no-store');
        }

        return response()->json(['success' => true, ...$result])
            ->header('Cache-Control', 'no-store');
    }
}
