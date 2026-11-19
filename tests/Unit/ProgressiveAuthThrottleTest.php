<?php

namespace Tests\Unit;

use App\Support\ProgressiveAuthThrottle;
use Tests\TestCase;

class ProgressiveAuthThrottleTest extends TestCase
{
    public function test_failure_rounds_cap_at_five_minutes_and_blocked_reads_do_not_extend_state(): void
    {
        $this->freezeTime();
        $key = ProgressiveAuthThrottle::key('test', 1);
        foreach ([60, 180, 300, 300] as $seconds) {
            foreach (range(1, 4) as $i) $this->assertNull(ProgressiveAuthThrottle::recordFailure($key));
            $this->assertSame($seconds, ProgressiveAuthThrottle::recordFailure($key)['retry_after']);
            $this->travel(1)->seconds();
            $this->assertSame($seconds - 1, ProgressiveAuthThrottle::blocked($key)['retry_after']);
            $this->travel($seconds - 1)->seconds();
            $this->assertNull(ProgressiveAuthThrottle::blocked($key));
        }
        ProgressiveAuthThrottle::clear($key);
        foreach (range(1, 4) as $i) ProgressiveAuthThrottle::recordFailure($key);
        $this->assertSame(60, ProgressiveAuthThrottle::recordFailure($key)['retry_after']);
        $this->travel(1800)->seconds();
        foreach (range(1, 4) as $i) $this->assertNull(ProgressiveAuthThrottle::recordFailure($key));
        $this->assertSame(60, ProgressiveAuthThrottle::recordFailure($key)['retry_after']);
    }

    public function test_sources_are_isolated_and_resend_progression_resets_after_inactivity(): void
    {
        $this->freezeTime();
        $first = ProgressiveAuthThrottle::key('test', 1);
        $this->app['request']->server->set('REMOTE_ADDR', '192.0.2.20');
        $this->assertNotSame($first, ProgressiveAuthThrottle::key('test', 1));
        $history = [];
        foreach ([60, 180, 300, 300] as $seconds) {
            $history[] = now()->timestamp;
            $this->assertSame($seconds, ProgressiveAuthThrottle::resendAfter($history));
            $this->travel($seconds)->seconds();
            $this->assertSame(0, ProgressiveAuthThrottle::resendAfter($history));
        }
        $this->travel(1800)->seconds();
        $history[] = now()->timestamp;
        $this->assertSame(60, ProgressiveAuthThrottle::resendAfter($history));
    }
}
