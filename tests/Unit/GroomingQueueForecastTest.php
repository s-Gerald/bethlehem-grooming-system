<?php

namespace Tests\Unit;

use App\Services\GroomingQueueForecast;
use Carbon\Carbon;
use Tests\TestCase;

class GroomingQueueForecastTest extends TestCase
{
    private function job(string $id, int $minutes, int $queue, ?int $groomer = 1, ?string $start = null): array
    {
        return ['key' => $id, 'duration' => ['min_minutes' => $minutes, 'max_minutes' => $minutes],
            'queue_number' => $queue, 'groomer_id' => $groomer, 'started_at' => $start];
    }

    private function project(array $jobs, array $groomers = [1], string $time = '13:00'): array
    {
        $date = fn ($time) => Carbon::parse('2026-10-31 '.$time, 'Asia/Manila');
        return (new GroomingQueueForecast)->project($jobs, $groomers, $date($time), $date('08:00'), $date('17:00'));
    }

    public function test_abc_forecast_uses_elapsed_active_work_and_upper_bound_closing_risk(): void
    {
        $forecast = $this->project([
            $this->job('B', 45, 2), $this->job('A', 90, 1, 1, '2026-10-31T13:00:00+08:00'),
            $this->job('C', 180, 3, null),
        ], time: '13:30');
        foreach (['A' => ['13:00','14:30'], 'B' => ['14:30','15:15'], 'C' => ['15:15','18:15']] as $id => [$start, $end]) {
            $this->assertSame($start, Carbon::parse($forecast['pets'][$id]['start_at'])->format('H:i'));
            $this->assertSame($end, Carbon::parse($forecast['pets'][$id]['completion_at'])->format('H:i'));
        }
        $this->assertTrue($forecast['pets']['C']['closing_risk']);
        $this->assertFalse($forecast['pets']['B']['closing_risk']);
    }

    public function test_parallel_lanes_respect_named_assignments_and_any_available(): void
    {
        $result = $this->project([$this->job('A', 90, 1), $this->job('B', 45, 2, 2),
            $this->job('C', 180, 3, null), $this->job('D', 45, 4, 1)], [1,2]);
        $this->assertSame('2026-10-31T13:45:00+08:00', $result['pets']['C']['start_at']);
        $this->assertSame(2, $result['pets']['C']['projected_groomer_id']);
        $this->assertSame('2026-10-31T14:30:00+08:00', $result['pets']['D']['start_at']);
        $this->assertFalse($result['closing_risk']);
    }

    public function test_overdue_sessions_invalidate_dependent_times_but_keep_provable_late_risk(): void
    {
        $result = $this->project([$this->job('A', 90, 1, 1, '2026-10-31T13:00:00+08:00'),
            $this->job('B', 45, 2), $this->job('C', 180, 3)], time: '15:00');
        $this->assertSame('delayed', $result['pets']['A']['status']);
        $this->assertNull($result['pets']['A']['completion_at']);
        $this->assertNull($result['pets']['B']['start_at']);
        $this->assertNull($result['pets']['C']['completion_at']);
        $this->assertTrue($result['pets']['C']['closing_risk']);
    }

    public function test_unknown_duration_and_off_duty_groomer_never_get_fabricated_times(): void
    {
        $unknown = $this->job('A', 90, 1);
        $unknown['duration'] = null;
        $result = $this->project([$unknown, $this->job('B', 45, 2), $this->job('C', 45, 3, 3)]);
        $this->assertNull($result['pets']['B']['start_at']);
        $this->assertSame('unavailable', $result['pets']['C']['status']);
        $this->assertNull($this->project([$this->job('A', 90, 1)], [])['pets']['A']['start_at']);
    }

    public function test_active_assignment_outside_on_duty_roster_keeps_remaining_capacity_uncertain(): void
    {
        $result = $this->project([$this->job('A', 90, 1, 3, '2026-10-31T13:00:00+08:00'), $this->job('B', 45, 2, 1)], [1]);
        $this->assertNull($result['pets']['B']['start_at']);
        $this->assertSame('uncertain', $result['pets']['B']['status']);
        $result = $this->project([$this->job('A', 90, 1, 3, '2026-10-31T13:00:00+08:00'),
            $this->job('B', 45, 2, 1, '2026-10-31T13:00:00+08:00'), $this->job('C', 45, 3, 1)], [1]);
        $this->assertNull($result['pets']['C']['start_at']);
    }

    public function test_conflicting_active_assignments_do_not_invent_concurrent_named_capacity(): void
    {
        $result = $this->project([$this->job('A', 90, 1, 1, '2026-10-31T13:00:00+08:00'),
            $this->job('B', 45, 2, 1, '2026-10-31T13:00:00+08:00'), $this->job('C', 45, 3, 1)], [1,2]);
        $this->assertNull($result['pets']['C']['start_at']);
    }

    public function test_known_late_start_can_warn_even_when_selected_duration_is_unavailable(): void
    {
        $unknown = $this->job('B', 0, 2);
        $unknown['duration'] = null;
        $earlier = $this->job('A', 180, 1);
        $earlier['duration']['min_minutes'] = 30;
        $result = $this->project([$earlier, $unknown], time: '15:00');
        $this->assertNull($result['pets']['B']['completion_at']);
        $this->assertTrue($result['pets']['B']['closing_risk']);
    }
}
