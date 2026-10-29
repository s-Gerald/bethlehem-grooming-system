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
                    $policy->send('second@gmail.com', fn () => $this->fail('A provider reservation is still uncommitted.'), 'password_recovery');
                    $this->fail('The outer transaction must retain the quota lock.');
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

    public function test_provider_pool_cannot_be_bypassed_by_changing_destination_source_or_purpose(): void
    {
        config(['authentication.email.provider_day' => 1]);
        $policy = app(AuthenticationEmailPolicy::class);
        $policy->send('first@gmail.com', fn () => 'queued', 'login');
        $this->app['request']->server->set('REMOTE_ADDR', '192.0.2.99');
        $this->assertSame(86400, $policy->status('second@gmail.com', 'password_recovery')['resend_after']);
        try {
            $policy->send('second@gmail.com', fn () => $this->fail('Provider pool must remain authoritative.'), 'password_recovery');
            $this->fail('Expected provider rejection.');
        } catch (HttpResponseException $error) { $this->assertSame('86400', $error->getResponse()->headers->get('Retry-After')); }
    }

    public function test_purpose_quota_and_source_reservations_are_restored_when_queue_insertion_fails(): void
    {
        $policy = app(AuthenticationEmailPolicy::class);
        try { $policy->send('customer@gmail.com', fn () => throw new \RuntimeException('queue failure'), 'login'); }
        catch (\RuntimeException) { /* Exercise the real reservation rollback. */ }
        $this->assertSame(0, $policy->status('customer@gmail.com', 'login')['resend_after']);
        config(['authentication.email.purposes.login.source_quarter_hour' => 1]);
        $this->assertSame('queued', $policy->send('customer@gmail.com', fn () => 'queued', 'login'));
        $this->assertSame(900, $policy->status('other@gmail.com', 'login')['resend_after']);
        $this->assertSame(0, $policy->status('other@gmail.com', 'password_recovery')['resend_after']);
    }

    public function test_purpose_cooldowns_are_independent_but_aggregate_destination_limit_still_applies(): void
    {
        $policy = app(AuthenticationEmailPolicy::class);
        config(['authentication.email.quarter_hour' => 2]);
        $policy->send('customer@gmail.com', fn () => 'queued', 'login');
        $this->assertSame(0, $policy->status('customer@gmail.com', 'password_recovery')['resend_after']);
        $this->assertSame('queued', $policy->send('customer@gmail.com', fn () => 'queued', 'password_recovery'));
        $this->assertSame(900, $policy->status('customer@gmail.com', 'privileged_change')['resend_after']);
        try {
            $policy->send('customer@gmail.com', fn () => $this->fail('Aggregate quota bypassed.'), 'privileged_change');
            $this->fail('Expected aggregate rejection.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(429, $exception->getResponse()->getStatusCode());
        }
    }

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
