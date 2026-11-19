<?php

namespace Tests\Unit;

use App\Services\AuthenticationEmailPolicy;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthenticationEmailPolicyTest extends TestCase
{
    public function test_outer_transaction_rollback_restores_nontransactional_cache_reservations_and_releases_locks(): void
    {
        $policy = app(AuthenticationEmailPolicy::class);
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($policy): void {
                $this->assertSame('queued', $policy->send('first@gmail.com', fn () => 'queued', 'privileged_change'));
                try {
                    $policy->send('second@gmail.com', fn () => $this->fail('A source reservation is still uncommitted.'), 'password_recovery');
                    $this->fail('The outer transaction must retain the source quota lock.');
                } catch (HttpResponseException $error) {
                    $this->assertSame('email_sending', $error->getResponse()->getData(true)['code']);
                }
                throw new \RuntimeException('A later issuance write rolled back the queued job.');
            });
        } catch (\RuntimeException) { /* The array cache models Redis's nontransactional writes. */ }
        $this->assertSame(0, $policy->status('first@gmail.com', 'privileged_change')['resend_after']);
        \Illuminate\Support\Facades\DB::transaction(fn () => $policy->send('first@gmail.com', fn () => 'queued', 'privileged_change'));
        $this->assertSame(60, $policy->status('first@gmail.com', 'privileged_change')['resend_after']);
        $this->assertSame('queued', $policy->send('second@gmail.com', fn () => 'queued', 'password_recovery'));
    }

    public function test_unrelated_destinations_and_ips_do_not_share_a_daily_limit(): void
    {
        $this->freezeTime();
        $policy = app(AuthenticationEmailPolicy::class);
        foreach (range(1, 101) as $i) {
            $this->app['request']->server->set('REMOTE_ADDR', '192.0.2.'.$i);
            $this->assertSame('queued', $policy->send("customer$i@gmail.com", fn () => 'queued', 'login'));
        }
    }

    public function test_interactive_sends_progress_across_purposes_without_destination_hour_or_day_limits(): void
    {
        $this->freezeTime();
        $policy = app(AuthenticationEmailPolicy::class);
        foreach (range(1, 55) as $i) {
            $seconds = [1 => 60, 2 => 180][$i] ?? 300;
            $policy->send('Customer@GMAIL.COM', fn () => 'queued', $i % 2 ? 'login' : 'password_recovery');
            $this->assertSame($seconds, $policy->status('customer@gmail.com', 'privileged_change')['resend_after']);
            $this->assertRejected($policy, $seconds, 'email_cooldown');
            $this->travel($seconds)->seconds();
        }
        $this->travel(1800)->seconds();
        $policy->send('customer@gmail.com', fn () => 'queued');
        $this->assertSame(60, $policy->status('customer@gmail.com')['resend_after']);
    }

    public function test_rejected_or_failed_issuance_does_not_advance_resend_progression(): void
    {
        $this->freezeTime();
        $policy = app(AuthenticationEmailPolicy::class);
        $policy->send('customer@gmail.com', fn () => 'queued');
        $this->assertRejected($policy, 60, 'email_cooldown');
        $this->travel(60)->seconds();
        try { $policy->send('customer@gmail.com', fn () => throw new \RuntimeException('failed')); }
        catch (\RuntimeException) {}
        $this->assertSame(0, $policy->status('customer@gmail.com')['resend_after']);
        $policy->send('customer@gmail.com', fn () => ['status' => 'invalid']);
        $this->assertSame(0, $policy->status('customer@gmail.com')['resend_after']);
        $policy->send('customer@gmail.com', fn () => 'queued');
        $this->assertSame(180, $policy->status('customer@gmail.com')['resend_after']);
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

    public function test_ip_hourly_and_daily_limits_apply_across_purposes_and_destinations(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(AuthenticationEmailPolicy::class);
        foreach (range(1, 200) as $i) {
            $policy->send("customer$i@gmail.com", fn () => 'queued', $i % 2 ? 'registration' : 'login');
            if ($i % 60 === 0) {
                $this->assertRejected($policy, 3600, 'email_rate_limited');
                $this->travel(1)->hour();
            }
        }
        $this->assertRejected($policy, 21 * 3600, 'email_rate_limited');
        $this->travel(21)->hours();
        $this->assertSame('queued', $policy->send('customer@gmail.com', fn () => 'queued', 'password_recovery'));
    }

    public function test_security_alerts_keep_their_daily_destination_cooldown(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(AuthenticationEmailPolicy::class);
        $policy->send('customer@gmail.com', fn () => 'queued', 'security_alert');
        $this->assertSame(86400, $policy->status('customer@gmail.com', 'security_alert')['resend_after']);
        $this->assertSame(0, $policy->status('customer@gmail.com', 'login')['resend_after']);
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
