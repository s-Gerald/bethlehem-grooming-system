<?php

namespace Tests\Unit;

use App\Services\AuthenticationEmailPolicy;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthenticationEmailPolicyTest extends TestCase
{
    public function test_initial_and_two_resends_share_the_rolling_quarter_hour(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(AuthenticationEmailPolicy::class);
        foreach (range(1, 3) as $i) {
            $policy->send('Customer@GMAIL.COM', fn () => 'queued');
            $status = $policy->status('customer@gmail.com');
            $this->assertSame($i === 3 ? 780 : 60, $status['resend_after']);
            $this->assertSame($i === 3, $status['resend_limited']);
            $this->travel(60)->seconds();
        }
        $this->assertRejected($policy, 720, 'email_rate_limited');
        $this->travel(720)->seconds();
        $this->assertSame('queued', $policy->send('customer@gmail.com', fn () => 'queued'));
    }

    public function test_hourly_and_daily_windows_count_all_initial_requests(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(AuthenticationEmailPolicy::class);
        // Three sends, then three more once the first quarter-hour rolls out.
        foreach ([0, 60, 60, 780, 60, 60] as $seconds) {
            $this->travel($seconds)->seconds();
            $policy->send('customer@gmail.com', fn () => null);
        }
        $this->assertSame(2580, $policy->status('customer@gmail.com')['resend_after']);
        $this->assertRejected($policy, 2580, 'email_rate_limited');
        $this->travel(3600)->seconds();
        foreach ([0, 60, 60, 780, 60, 60] as $seconds) {
            $this->travel($seconds)->seconds();
            $policy->send('customer@gmail.com', fn () => null);
        }
        $this->assertSame(80760, $policy->status('customer@gmail.com')['resend_after']);
        $this->assertRejected($policy, 80760, 'email_rate_limited');
        $this->travel(80760)->seconds();
        $this->assertSame('queued', $policy->send('customer@gmail.com', fn () => 'queued'));
    }

    public function test_failure_preserves_quota_and_simultaneous_sends_cannot_enter_the_callback(): void
    {
        $policy = app(AuthenticationEmailPolicy::class);
        try { $policy->send('customer@gmail.com', fn () => throw new \RuntimeException('queue failed')); }
        catch (\RuntimeException) { $this->assertSame(0, $policy->status('customer@gmail.com')['resend_after']); }
        $lock = Cache::lock('auth-email:destination:'.hash('sha256', 'customer@gmail.com').':lock', 30);
        $this->assertTrue($lock->get());
        try { $this->assertRejected($policy, 1, 'email_sending'); }
        finally { $lock->release(); }
        $this->assertSame('queued', $policy->send('customer@gmail.com', fn () => 'queued'));
        $this->assertRejected($policy, 60, 'email_cooldown');
    }

    public function test_ip_quota_is_shared_across_destinations(): void
    {
        config()->set('authentication.email.ip_hour', 2);
        $policy = app(AuthenticationEmailPolicy::class);
        $policy->send('first@gmail.com', fn () => null);
        $policy->send('second@gmail.com', fn () => null);
        $this->assertSame(3600, $policy->status('third@gmail.com')['resend_after']);
    }

    private function assertRejected(AuthenticationEmailPolicy $policy, int $seconds, string $code): void
    {
        try {
            $policy->send('customer@gmail.com', fn () => $this->fail('A rejected send must never queue mail.'));
            $this->fail('Expected HTTP 429.');
        } catch (HttpResponseException $error) {
            $this->assertSame(429, $error->getResponse()->getStatusCode());
            $this->assertSame((string) $seconds, $error->getResponse()->headers->get('Retry-After'));
            $this->assertSame($code, $error->getResponse()->getData(true)['code']);
        }
    }
}
