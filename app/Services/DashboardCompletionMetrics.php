<?php

namespace App\Services;

use App\Models\ClinicAppointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardCompletionMetrics
{
    public function today(bool $clinicOnly = false, bool $includeTotal = false): array
    {
        $day = CarbonImmutable::today('Asia/Manila');
        $start = $day->toDateTimeString();
        $until = $day->addDay()->toDateTimeString();
        $clinic = $this->clinic($start, $until);
        if ($clinicOnly) return ['clinic' => $clinic];

        $grooming = $this->grooming($start, $until);
        return ['grooming' => $grooming, 'clinic' => $clinic,
            ...($includeTotal ? ['total' => $grooming + $clinic] : [])];
    }

    private function grooming(string $start, string $until): int
    {
        // Each paid row represents full settlement. Keep the first confirmation
        // so duplicate audit rows cannot multiply pets or move completion dates.
        $payments = DB::table('payments')->where('payment_status', 'paid')->whereNotNull('paid_at')
            ->select('booking_id')->selectRaw('MIN(paid_at) as paid_at')->groupBy('booking_id');
        $visits = DB::table('bookings as b')->join('booking_pets as bp', 'bp.booking_id', '=', 'b.booking_id')
            ->joinSub($payments, 'payment', 'payment.booking_id', '=', 'b.booking_id')
            ->whereIn('b.status', ['released', 'completed', 'archived'])->where('b.paid', true)
            ->whereNull('bp.cancelled_at')->where(function ($q) {
                $q->whereNull('bp.grooming_state')->orWhere('bp.grooming_state', '!=', 'cancelled');
            })
            ->where('payment.paid_at', '<', $until)
            ->groupBy('b.booking_id', 'b.grooming_finished_at', 'payment.paid_at')
            ->havingRaw("SUM(CASE WHEN bp.grooming_state = 'finished' AND bp.grooming_end_time IS NOT NULL THEN 0 ELSE 1 END) = 0")
            ->selectRaw('COUNT(*) as pets, payment.paid_at,
                CASE WHEN b.grooming_finished_at > MAX(bp.grooming_end_time)
                    THEN b.grooming_finished_at ELSE MAX(bp.grooming_end_time) END as finished_at');

        return (int) DB::query()->fromSub($visits, 'finished_visits')
            ->where('finished_at', '<', $until)
            ->where(fn ($q) => $q->where('finished_at', '>=', $start)->orWhere('paid_at', '>=', $start))
            ->sum('pets');
    }

    private function clinic(string $start, string $until): int
    {
        if (! Schema::hasTable('clinic_appointments')) return 0;

        // Settlement is authoritative for paid_at; service finish is independent.
        // Both must exist and their later timestamp must fall within today's range.
        return ClinicAppointment::whereIn('status', ['completed', 'archived'])->where('paid', true)
            ->where('consultation_finished_at', '<', $until)->where('paid_at', '<', $until)
            ->where(fn ($q) => $q->where('consultation_finished_at', '>=', $start)->orWhere('paid_at', '>=', $start))
            ->count();
    }
}
