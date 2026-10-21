<?php

namespace Tests\Unit;

use App\Models\ClinicSetting;
use App\Services\GroomingWorkloadCapacity;
use App\Services\AvailabilityTimeWindowService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingWorkloadCapacityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(int $count = 1, string $close = '17:00'): ClinicSetting
    {
        return (new ClinicSetting(['grooming_open_time' => '08:00', 'grooming_close_time' => $close]))->forceFill(['groomers_on_duty' => $count]);
    }

    private function job(int $id, int $minutes, ?string $start = null): array
    {
        return ['id' => $id, 'minutes' => $minutes, 'active' => $start !== null, 'started_at' => $start];
    }

    #[DataProvider('laneCounts')]
    public function test_parallel_lanes_preserve_durations_and_queue_order(int $lanes): void
    {
        $jobs = array_map(fn ($id) => $this->job($id, 60), range(1, 6));
        $result = app(GroomingWorkloadCapacity::class)->project($jobs, [], $this->settings($lanes), now());
        foreach ($jobs as $index => $job) {
            $pet = $result['pets'][$job['id']];
            $expected = now()->addMinutes((int) floor($index / $lanes) * 60);
            $this->assertSame($expected->toIso8601String(), $pet['projected_start']);
            $this->assertSame($expected->addHour()->toIso8601String(), $pet['projected_completion']);
        }
    }

    public static function laneCounts(): array { return [[1], [2], [3], [4], [5]]; }

    #[DataProvider('emptyWorkloadTimes')]
    public function test_empty_workload_stays_neutral_without_changing_hours_restrictions(string $time, string $close, bool $fits): void
    {
        Carbon::setTestNow('2026-10-09 '.$time);
        $result = app(GroomingWorkloadCapacity::class)->project([], [], $this->settings(2, $close), now());

        $this->assertSame('On track', $result['state']);
        $this->assertNull($result['projected_last_completion']);
        $this->assertSame([], $result['pets']);
        $this->assertSame(0, $result['waiting']);
        $this->assertSame(0, $result['in_progress']);
        $this->assertSame($fits, $result['fits']);
    }

    public static function emptyWorkloadTimes(): array
    {
        return [
            'normal hours' => ['15:00:00', '17:00', true],
            'risk boundary' => ['16:30:00', '17:00', true],
            'shortly before closing' => ['16:45:00', '17:00', true],
            'exact closing' => ['17:00:00', '17:00', true],
            'after closing' => ['17:01:00', '17:00', false],
            'updated closing risk window' => ['17:45:00', '18:00', true],
            'after updated closing' => ['18:01:00', '18:00', false],
        ];
    }

    public function test_ongoing_grooming_after_closing_still_needs_staff_action(): void
    {
        Carbon::setTestNow('2026-10-09 17:01:00');
        $result = app(GroomingWorkloadCapacity::class)->project(
            [$this->job(1, 60, '2026-10-09 16:00:00')], [], $this->settings(), now());

        $this->assertSame('Needs staff action', $result['state']);
        $this->assertSame('Needs staff action', $result['pets'][1]['state']);
        $this->assertSame(1, $result['in_progress']);
        $this->assertFalse($result['fits']);
        $this->assertGreaterThan($result['closing_time'], $result['projected_last_completion']);
    }

    public function test_queued_workload_warning_uses_updated_closing_hours(): void
    {
        Carbon::setTestNow('2026-10-09 16:00:00');
        $engine = app(GroomingWorkloadCapacity::class);
        foreach (['16:30' => 'Needs staff action', '17:00' => 'At risk', '18:00' => 'On track'] as $close => $state) {
            $result = $engine->project([$this->job(1, 45)], [], $this->settings(1, $close), now());
            $this->assertSame($state, $result['state']);
            $this->assertSame($state, $result['pets'][1]['state']);
            $this->assertSame($state !== 'Needs staff action', $result['fits']);
            $this->assertSame($close, Carbon::parse($result['closing_time'])->format('H:i'));
        }
    }

    public function test_zero_on_duty_has_no_lane_for_waiting_or_arriving_pets(): void
    {
        $result = app(GroomingWorkloadCapacity::class)->project([$this->job(1, 60)], [$this->job(2, 45)], $this->settings(0), now());
        $this->assertSame(0, $result['groomers_on_duty']);
        $this->assertFalse($result['fits']);
        $this->assertNull($result['pets'][1]['projected_start']);
        $this->assertNull($result['pets'][2]['projected_completion']);
    }

    public function test_active_remaining_work_occupies_lanes_before_waiting_and_new_arrivals(): void
    {
        $jobs = [$this->job(1, 90, '2026-10-09 14:30'), $this->job(2, 60, '2026-10-09 14:30'), $this->job(3, 45)];
        $result = app(GroomingWorkloadCapacity::class)->project($jobs, [$this->job(4, 60)], $this->settings(2), now());
        $this->assertSame('15:30', Carbon::parse($result['pets'][3]['projected_start'])->format('H:i'));
        $this->assertSame('16:00', Carbon::parse($result['pets'][4]['projected_start'])->format('H:i'));
        $this->assertTrue($result['fits']); // equality at closing is accepted
        $this->assertSame('At risk', $result['pets'][4]['state']);
    }

    public function test_reduced_count_preserves_excess_active_sessions_and_delays_future_starts(): void
    {
        $jobs = [$this->job(1, 90, '2026-10-09 14:30'), $this->job(2, 60, '2026-10-09 14:30'), $this->job(3, 60)];
        $result = app(GroomingWorkloadCapacity::class)->project($jobs, [], $this->settings(1), now());
        $this->assertSame(2, $result['in_progress']);
        $this->assertSame('15:30', Carbon::parse($result['pets'][2]['projected_completion'])->format('H:i'));
        $this->assertSame('16:00', Carbon::parse($result['pets'][3]['projected_start'])->format('H:i'));
        $this->assertSame('17:00', Carbon::parse($result['projected_last_completion'])->format('H:i'));
    }

    public function test_explicit_late_arrival_uses_configured_closing(): void
    {
        $engine = app(GroomingWorkloadCapacity::class);
        $result = $engine->project([], [$this->job(1, 60)], $this->settings(3), now(), now()->setTime(16, 30));
        $this->assertFalse($result['fits']);
        $this->assertSame('Needs staff action', $result['pets'][1]['state']);
        $this->assertTrue($engine->project([], [$this->job(1, 60)], $this->settings(3, '18:00'), now(), now()->setTime(16, 30))['fits']);
    }

    public function test_current_time_and_overdue_sessions_are_not_free_capacity(): void
    {
        $result = app(GroomingWorkloadCapacity::class)->project([$this->job(1, 90, '2026-10-09 12:00')],
            [$this->job(2, 60)], $this->settings(), now()->startOfDay());
        $this->assertSame('16:30', Carbon::parse($result['pets'][2]['projected_start'])->format('H:i'));
        $this->assertFalse($result['fits']);
    }

    public function test_more_groomers_change_capacity_without_changing_individual_durations(): void
    {
        $engine = app(GroomingWorkloadCapacity::class);
        $jobs = [$this->job(1, 90), $this->job(2, 90)];
        $this->assertFalse($engine->project($jobs, [], $this->settings(1), now())['fits']);
        $result = $engine->project($jobs, [], $this->settings(2), now());
        $this->assertTrue($result['fits']);
        $this->assertEquals(60, $result['remaining_lane_minutes']);
        $this->assertSame('16:30', Carbon::parse($result['pets'][1]['projected_completion'])->format('H:i'));
    }

    public function test_missing_estimates_do_not_silently_reserve_zero_minutes(): void
    {
        $result = app(GroomingWorkloadCapacity::class)->project([['id' => 1, 'minutes' => null, 'active' => false]],
            [$this->job(2, 60)], $this->settings(), now());
        $this->assertFalse($result['fits']);
        $this->assertNull($result['projected_last_completion']);
        $this->assertSame('Needs staff action', $result['state']);
        $this->assertSame('Needs staff action', $result['pets'][1]['state']);
    }

    public function test_empty_and_future_workloads_start_at_configured_opening_time(): void
    {
        $result = app(GroomingWorkloadCapacity::class)->project([], [$this->job(1, 60)], $this->settings(), now()->addDay()->startOfDay());
        $this->assertSame('08:00', Carbon::parse($result['pets'][1]['projected_start'])->format('H:i'));
        $this->assertSame('On track', $result['state']);
        $this->assertTrue($result['fits']);
    }

    #[DataProvider('arrivalWorkloads')]
    public function test_arrival_windows_use_selected_duration_existing_work_and_parallel_groomers(
        array $jobs, array $reservations, int $minutes, int $groomers, bool $available,
    ): void {
        $engine = $this->getMockBuilder(GroomingWorkloadCapacity::class)
            ->setConstructorArgs([app(\App\Services\GroomingTimeEstimate::class), app(\App\Services\OperationalCapacity::class)])
            ->onlyMethods(['liveJobs', 'reservedJobs'])->getMock();
        $engine->method('liveJobs')->willReturn(collect($jobs));
        foreach ($reservations as &$job) $job['arrival_at'] = now()->setTime(16, 0)->timestamp;
        unset($job);
        $engine->method('reservedJobs')->willReturn($reservations);
        $windows = collect([['window_id' => 1, 'start_time' => '15:00:00', 'end_time' => '16:00:00', 'is_past' => false, 'is_closed' => false]]);
        $result = $engine->forecastWindows($windows, now()->toDateString(),
            [['id' => 'arrival-0', 'minutes' => $minutes, 'active' => false]], $this->settings($groomers));
        $this->assertSame(! $available, $result[0]['is_workload_unavailable']);
        $this->assertSame($available ? null : GroomingWorkloadCapacity::UNAVAILABLE, $result[0]['workload_message']);
    }

    public static function arrivalWorkloads(): array
    {
        $waiting = ['id' => 1, 'minutes' => 90, 'active' => false];
        $active = ['id' => 1, 'minutes' => 90, 'active' => true, 'started_at' => '2026-10-09 14:30'];
        $reservation = ['id' => 2, 'minutes' => 60, 'active' => false];
        return [
            'empty queue fits at closing' => [[], [], 120, 1, true],
            'empty queue runs past closing' => [[], [], 121, 1, false],
            'waiting pet still leaves room' => [[$waiting], [], 30, 1, true],
            'waiting pet pushes past closing' => [[$waiting], [], 31, 1, false],
            'active pet still leaves room' => [[$active], [], 60, 1, true],
            'parallel groomer leaves room' => [[$waiting], [], 60, 2, true],
            'reservation fills remaining time' => [[], [$reservation], 61, 1, false],
            'reservation shares parallel groomers' => [[], [$reservation], 60, 2, true],
        ];
    }

    public function test_reservation_revalidates_workload_after_a_window_was_available(): void
    {
        $engine = $this->getMockBuilder(GroomingWorkloadCapacity::class)
            ->setConstructorArgs([app(\App\Services\GroomingTimeEstimate::class), app(\App\Services\OperationalCapacity::class)])
            ->onlyMethods(['liveJobs', 'reservedJobs'])->getMock();
        $engine->method('liveJobs')->willReturnOnConsecutiveCalls(collect(), collect([$this->job(1, 120)]));
        $engine->method('reservedJobs')->willReturn([]);
        $windows = collect([['window_id' => 1, 'start_time' => '15:00:00', 'end_time' => '16:00:00', 'is_past' => false, 'is_closed' => false, 'is_past' => false, 'is_cutoff' => false, 'is_closed' => false]]);
        $this->mock(AvailabilityTimeWindowService::class)->shouldReceive('preferredWindows')->twice()->andReturn($windows);
        $arrivals = [['id' => 'arrival-0', 'minutes' => 60, 'active' => false]];
        $engine->assertCanReserve(now()->toDateString(), 1, $arrivals, $this->settings());
        try {
            $engine->assertCanReserve(now()->toDateString(), 1, $arrivals, $this->settings());
            $this->fail('Submission must reject a window when the workload has changed.');
        } catch (ValidationException $error) {
            $this->assertSame('grooming_forecast_unavailable', $error->response->getData(true)['code']);
        }
    }

    #[DataProvider('effectiveArrivals')]
    public function test_windows_project_from_now_or_the_future_start_and_shorten_the_safe_range(
        string $time, string $date, string $start, string $end, string $close,
        array $jobs, int $groomers, bool $fits, string $completion, ?string $latest,
    ): void {
        Carbon::setTestNow('2026-10-09 '.$time);
        $projector = app(GroomingWorkloadCapacity::class);
        $engine = $this->getMockBuilder(GroomingWorkloadCapacity::class)
            ->setConstructorArgs([app(\App\Services\GroomingTimeEstimate::class), app(\App\Services\OperationalCapacity::class)])
            ->onlyMethods(['liveJobs', 'reservedJobs', 'project'])->getMock();
        $engine->method('liveJobs')->willReturn(collect($jobs));
        $engine->method('reservedJobs')->willReturn([]);
        $firstProjection = null;
        $engine->method('project')->willReturnCallback(function (...$arguments) use ($projector, &$firstProjection) {
            $result = $projector->project(...$arguments);
            $firstProjection ??= $result;
            return $result;
        });
        $window = ['window_id' => 1, 'start_time' => $start, 'end_time' => $end,
            'window_label' => 'original', 'is_past' => false, 'is_closed' => false];
        $result = $engine->forecastWindows(collect([$window]), $date,
            [['id' => 'arrival-0', 'minutes' => 90, 'active' => false]], $this->settings($groomers, $close))[0];
        $this->assertSame(! $fits, $result['is_workload_unavailable']);
        $this->assertSame($completion, Carbon::parse($firstProjection['pets']['arrival-0']['projected_completion'])->format('H:i:s'));
        $this->assertSame($latest, $result['latest_arrival_time']);
        if ($latest !== null) $this->assertSame($latest, $result['end_time']);
        if ($latest === '15:30:00') $this->assertSame('3:00 PM - 3:30 PM', $result['window_label']);
    }

    public static function effectiveArrivals(): array
    {
        $active = ['id' => 1, 'minutes' => 90, 'active' => true, 'started_at' => '2026-10-09 14:00:00'];
        $waiting = ['id' => 2, 'minutes' => 30, 'active' => false];
        return [
            '9:47 uses actual current time' => ['09:47:00', '2026-10-09', '09:00:00', '10:00:00', '17:00', [], 1, true, '11:17:00', null],
            'later today starts at 3pm' => ['14:33:00', '2026-10-09', '15:00:00', '16:00:00', '17:00', [], 1, true, '16:30:00', '15:30:00'],
            'future day ignores todays clock' => ['23:00:00', '2026-10-10', '15:00:00', '16:00:00', '17:00', [], 1, true, '16:30:00', '15:30:00'],
            'active window finishes exactly at closing' => ['15:30:00', '2026-10-09', '15:00:00', '16:00:00', '17:00', [], 1, true, '17:00:00', '15:30:00'],
            'one second after safe arrival is rejected' => ['15:30:01', '2026-10-09', '15:00:00', '16:00:00', '17:00', [], 1, false, '17:00:01', null],
            'current window start cannot hide overtime' => ['15:47:00', '2026-10-09', '15:00:00', '16:00:00', '17:00', [], 1, false, '17:17:00', null],
            'in progress workload finishes at closing' => ['14:33:00', '2026-10-09', '15:00:00', '16:00:00', '17:00', [$active], 1, true, '17:00:00', '15:30:00'],
            'queued and active workload exceeds closing' => ['14:33:00', '2026-10-09', '15:00:00', '16:00:00', '17:00', [$active, $waiting], 1, false, '17:30:00', null],
            'parallel capacity preserves a safe window' => ['14:33:00', '2026-10-09', '15:00:00', '16:00:00', '17:00', [$active, $waiting], 2, true, '16:33:00', '15:30:00'],
        ];
    }
}
