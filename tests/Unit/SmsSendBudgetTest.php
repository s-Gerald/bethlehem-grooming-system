<?php

namespace Tests\Unit;

use App\Services\SmsSendBudget;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class SmsSendBudgetTest extends TestCase
{
    public function test_ip_emergency_ceiling_is_sixty_per_hour(): void
    {
        $this->freezeTime();
        $budget = app(SmsSendBudget::class);
        foreach (range(1, 60) as $i) $budget->reserve(1, '09171234567', '192.0.2.1');
        $this->assertLimited(fn () => $budget->reserve(1, '09171234567', '192.0.2.1'), 3600);
        $this->travel(3600)->seconds();
        $budget->reserve(1, '09171234567', '192.0.2.1');
    }

    public function test_global_hour_and_day_emergency_ceilings_remain(): void
    {
        $this->freezeTime();
        $budget = app(SmsSendBudget::class);
        foreach (range(1, 5) as $hour) {
            foreach (range(1, 100) as $i) $budget->reserve(1, '09171234567');
            $this->assertLimited(fn () => $budget->reserve(1, '09171234567'), $hour === 5 ? 72000 : 3600);
            $this->travel(3600)->seconds();
        }
        $this->assertLimited(fn () => $budget->reserve(1, '09171234567'), 68400);
    }

    private function assertLimited(callable $reserve, int $seconds): void
    {
        try {
            $reserve();
            $this->fail('The emergency ceiling must block further provider reservations.');
        } catch (HttpResponseException $error) {
            $response = $error->getResponse();
            $this->assertSame(429, $response->getStatusCode());
            $this->assertSame($seconds, $response->getData(true)['retry_after']);
            $this->assertSame((string) $seconds, $response->headers->get('Retry-After'));
        }
    }
}
