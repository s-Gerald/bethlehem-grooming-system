<?php

namespace Tests\Feature;

use App\Models\LoginEmailChallenge;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    public function test_in_process_worker_drains_its_named_queue_above_laravels_default_memory_threshold(): void
    {
        $this->useDatabaseMailQueue();
        config(['mail.authentication.queue' => 'worker-test']);
        foreach (User::factory()->count(3)->create(['role' => 'admin']) as $user) {
            $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertAccepted();
        }
        DB::table('jobs')->where('id', DB::table('jobs')->min('id'))->update(['queue' => 'other-test']);

        // Reproduce full-suite memory pressure without retaining it after this test.
        $padding = str_repeat('x', max(0, 130 * 1024 * 1024 - memory_get_usage(true)));
        try {
            $this->workQueue(['--once' => true, '--sleep' => 0, '--queue' => 'worker-test'])->assertExitCode(0);
            $this->assertDatabaseCount('jobs', 2);
            $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
            $this->workQueue(['--stop-when-empty' => true, '--sleep' => 0, '--queue' => 'worker-test'])->assertExitCode(0);
            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame('other-test', DB::table('jobs')->sole()->queue);
            $this->assertCount(2, app('mailer')->getSymfonyTransport()->messages());
        } finally {
            unset($padding);
        }
    }

    public function test_in_process_worker_preserves_explicit_memory_limit_failures(): void
    {
        $this->useDatabaseMailQueue();
        $this->workQueue(['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1])
            ->assertExitCode(\Illuminate\Queue\Worker::EXIT_MEMORY_LIMIT);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_security_activity_focus_keeps_an_older_owned_incident_in_the_bounded_list(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        foreach (range(0, 20) as $days) {
            $incident = \App\Models\AccountSecurityIncident::create(['user_id' => $user->getKey(),
                'incident_day' => now()->subDays($days)->toDateString(), 'first_occurred_at' => now()->subDays($days),
                'last_occurred_at' => now()->subDays($days), 'reviewed_at' => $days === 20 ? null : now()]);
        }
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->getJson('/api/settings/security/activity?incident='.$incident->getKey())->assertOk()
            ->assertJsonCount(20, 'incidents')->assertJsonPath('incidents.0.id', $incident->getKey());
        $foreign = \App\Models\AccountSecurityIncident::create(['user_id' => User::factory()->create()->getKey(),
            'incident_day' => now()->toDateString(), 'first_occurred_at' => now(), 'last_occurred_at' => now()]);
        $this->getJson('/api/settings/security/activity?incident='.$foreign->getKey())->assertOk()
            ->assertJsonMissing(['id' => $foreign->getKey()]);
    }

    public function test_security_cleanup_is_bounded_and_preserves_unexpired_challenges_and_recent_incidents(): void
    {
        $user = User::factory()->create();
        foreach ([-2, -1, 10] as $i => $minutes) {
            LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', 'poll-'.$i),
                'token_hash' => hash('sha256', 'code-'.$i), 'expires_at' => now()->addMinutes($minutes)]);
        }
        foreach ([92, 91, 0] as $days) {
            \App\Models\AccountSecurityIncident::create(['user_id' => $user->getKey(), 'incident_day' => now()->subDays($days)->toDateString(),
                'first_occurred_at' => now()->subDays($days), 'last_occurred_at' => now()->subDays($days)]);
        }
        $this->assertSame(1, LoginEmailChallenge::pruneExpired(1));
        $this->assertDatabaseCount('login_email_challenges', 2);
        $this->assertSame(1, \App\Models\AccountSecurityIncident::pruneExpired(1));
        $this->assertDatabaseCount('account_security_incidents', 2);
        $this->assertSame(1, LoginEmailChallenge::pruneExpired());
        $this->assertSame(1, \App\Models\AccountSecurityIncident::pruneExpired());
        $this->assertTrue(LoginEmailChallenge::sole()->expires_at->isFuture());
        $this->assertTrue(\App\Models\AccountSecurityIncident::sole()->last_occurred_at->isAfter(now()->subDay()));
    }

    public function test_warning_mail_is_encrypted_durable_and_contains_no_authentication_material(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create(['role' => 'admin']);
        $login = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertAccepted();
        $command = json_decode(DB::table('jobs')->sole()->payload, true)['data']['command'];
        $code = unserialize(app('encrypter')->decrypt($command))->notification->code;
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'),
                'code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('jobs', 2);
        $warning = json_decode(DB::table('jobs')->orderByDesc('id')->value('payload'), true)['data']['command'];
        $this->assertStringNotContainsString('UnusualSignInActivityNotification', $warning);
        $this->assertInstanceOf(\App\Notifications\UnusualSignInActivityNotification::class,
            unserialize(app('encrypter')->decrypt($warning))->notification);
        $this->workQueue(['--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        $mail = $messages[1]->getOriginalMessage();
        $this->assertSame('Unusual sign-in activity - Bethlehem Animal Clinic', $mail->getSubject());
        $this->assertStringNotContainsString($code, $mail->getHtmlBody());
        $this->assertStringNotContainsString($login->json('login_poll_token'), $mail->getHtmlBody());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_database_cache_preserves_failure_budgets_and_serializes_source_contention(): void
    {
        (require base_path('database/migrations/0001_01_01_000001_create_cache_table.php'))->up();
        config(['cache.default' => 'database']);
        $this->app->forgetInstance(\Illuminate\Cache\RateLimiter::class);
        RateLimiter::clearResolvedInstance('cache.rate_limiter');
        $user = User::factory()->create();
        $token = str_repeat('d', 64);
        $challenge = LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', $token),
            'token_hash' => hash_hmac('sha256', '123456', $token), 'expires_at' => now()->addMinutes(10)]);
        $lock = Cache::lock('login-code-ip-lock:'.hash('sha256', '127.0.0.1'), 30);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => '000000'])
                ->assertTooManyRequests()->assertHeader('Retry-After', '1')->assertJsonPath('code', 'login_code_busy');
            $this->assertSame(0, $challenge->fresh()->failed_attempts);
        } finally { $lock->release(); }
        $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => '000000'])->assertUnprocessable();
        app('cache')->forgetDriver('database');
        $this->assertSame(1, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => '123456'])->assertOk();
        $this->assertSame(0, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        $this->assertSame(1, RateLimiter::attempts('login-code-ip:'.hash('sha256', '127.0.0.1')));
    }

    public function test_security_activity_requires_fully_authenticated_privileged_access(): void
    {
        $response = $this->getJson('/api/settings/security/activity')->assertUnauthorized();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/settings/security/activity')->assertForbidden();
        $this->postJson('/api/settings/security/activity/1/review')->assertForbidden();
    }

    public function test_warning_queue_failure_preserves_the_incident_and_allows_a_valid_otp(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create(['role' => 'admin']);
        $login = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertAccepted();
        $command = json_decode(DB::table('jobs')->sole()->payload, true)['data']['command'];
        $code = unserialize(app('encrypter')->decrypt($command))->notification->code;
        DB::statement("CREATE TRIGGER reject_security_warning BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'),
                'code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('account_security_incidents', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $code])
            ->assertOk()->assertJsonPath('security_incident_id', \App\Models\AccountSecurityIncident::sole()->id);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame(3, RateLimiter::attempts('login-code-ip:'.hash('sha256', '127.0.0.1')));
    }

    public function test_daily_incidents_do_not_bypass_the_rolling_warning_deduplication(): void
    {
        Notification::fake();
        $this->travelTo(now()->utc()->setTime(23, 59));
        $user = User::factory()->create(['role' => 'admin']);
        foreach (['a', 'b'] as $index => $letter) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)]);
            $token = str_repeat($letter, 64);
            LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', $token),
                'token_hash' => hash_hmac('sha256', '123456', $token), 'expires_at' => now()->addMinutes(10)]);
            foreach (range(1, 3) as $i) $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => '000000'])->assertUnprocessable();
            $this->travel(2)->minutes();
        }
        $this->assertDatabaseCount('account_security_incidents', 2);
        Notification::assertSentToTimes($user, \App\Notifications\UnusualSignInActivityNotification::class, 1);
    }

    public function test_untrusted_forwarding_headers_cannot_evade_the_account_source_budget(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 6) as $i) {
            $token = str_pad((string) $i, 64, 'a');
            LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', $token),
                'token_hash' => hash_hmac('sha256', '123456', $token), 'expires_at' => now()->addMinutes(10)]);
            $this->withHeader('X-Forwarded-For', '192.0.2.'.$i)->postJson('/api/email/login/confirm', [
                'poll_token' => $token, 'code' => $i === 6 ? '123456' : '000000'])->assertStatus($i >= 5 ? 429 : 422);
        }
        $this->assertSame(5, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_independent_challenge_migration_can_resume_without_replacing_existing_challenges(): void
    {
        $user = User::factory()->create();
        LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', 'one'),
            'token_hash' => hash('sha256', 'code-one'), 'expires_at' => now()->addMinutes(10)]);
        $before = LoginEmailChallenge::sole()->getAttributes();
        (require base_path('database/migrations/2026_10_30_000001_isolate_login_email_challenges.php'))->up();
        LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', 'two'),
            'token_hash' => hash('sha256', 'code-two'), 'expires_at' => now()->addMinutes(10)]);
        $this->assertDatabaseCount('login_email_challenges', 2);
        $this->assertSame($before['token_hash'], LoginEmailChallenge::find($before['id'])->token_hash);
    }

    public function test_repeated_otp_failures_create_one_private_incident_and_one_warning_without_revoking_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'admin']);
        $active = $user->createToken('existing-session')->accessToken;
        $login = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertAccepted();
        $wrong = $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000';
        $payload = ['poll_token' => $login->json('login_poll_token'), 'code' => $wrong];
        $this->postJson('/api/email/login/confirm', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('account_security_incidents', 0);
        foreach (range(2, 4) as $i) $this->postJson('/api/email/login/confirm', $payload)->assertUnprocessable();
        $incident = \App\Models\AccountSecurityIncident::sole();
        $this->assertSame(4, $incident->failure_count);
        $this->assertNull($incident->reviewed_at);
        $this->assertNull($active->fresh()->replaced_at);
        Notification::assertSentToTimes($user, \App\Notifications\UnusualSignInActivityNotification::class, 1);
        $other = User::factory()->create(['role' => 'staff']);
        \Laravel\Sanctum\Sanctum::actingAs($other);
        $this->getJson('/api/settings/security/activity')->assertOk()->assertJsonCount(0, 'incidents');
        $this->postJson('/api/settings/security/activity/'.$incident->getKey().'/review')->assertNotFound();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->getJson('/api/settings/security/activity')->assertOk()->assertJsonPath('incidents.0.failure_count', 4);
        $this->postJson('/api/settings/security/activity/'.$incident->getKey().'/review')->assertOk();
        $this->assertNotNull($incident->fresh()->reviewed_at);
        $this->assertSame(4, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        $this->assertNull($active->fresh()->replaced_at);
        $good = Notification::sent($user, ConfirmLoginNotification::class)->last()->code;
        $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $good])->assertOk();
        $token = str_repeat('n', 64);
        LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', $token),
            'token_hash' => hash_hmac('sha256', '123456', $token), 'expires_at' => now()->addMinutes(10)]);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => '000000'])->assertUnprocessable();
        $this->assertSame(5, $incident->fresh()->failure_count, 'A successful sign-in clears guessing budgets, not incident history.');
        $this->assertNull($incident->fresh()->reviewed_at);
        Notification::assertSentToTimes($user, \App\Notifications\UnusualSignInActivityNotification::class, 1);
    }

    public function test_twenty_distributed_failures_exhaust_the_account_budget_without_resend_reset(): void
    {
        Notification::fake();
        config(['authentication.email.quarter_hour' => 30, 'authentication.email.hour' => 30,
            'authentication.email.day' => 30, 'authentication.email.purposes.login.quarter_hour' => 30]);
        $user = User::factory()->create();
        $firstToken = null;
        foreach (range(1, 4) as $source) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$source]);
            $this->travel(61)->seconds();
            $login = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertAccepted();
            $token = $login->json('login_poll_token');
            $firstToken ??= $token;
            $wrong = $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000';
            foreach (range(1, 5) as $i) {
                $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $wrong])->assertStatus($i === 5 ? 429 : 422);
            }
        }
        $this->assertSame(20, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson('/api/email/login/resend', ['poll_token' => $firstToken])->assertTooManyRequests();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->travel(901)->seconds();
        $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertAccepted();
    }

    public function test_outstanding_challenge_limit_rejects_new_work_without_invalidating_a_valid_code(): void
    {
        Notification::fake();
        config(['authentication.outstanding_challenges' => 1]);
        $user = User::factory()->create();
        $payload = ['identifier' => $user->email, 'password' => 'password'];
        $login = $this->postJson('/api/sign-in', $payload)->assertAccepted();
        $code = $this->loginCodeSentTo($user);
        $this->travel(61)->seconds();
        $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('code', 'login_challenges_limited');
        $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $code])->assertOk();
    }

    public function test_independent_login_challenges_survive_other_browser_issuance_and_resend(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'admin']);
        $payload = ['identifier' => $user->email, 'password' => 'password'];
        $first = $this->postJson('/api/sign-in', $payload)->assertAccepted();
        $firstCode = $this->loginCodeSentTo($user);
        $this->travel(61)->seconds();
        $second = $this->postJson('/api/sign-in', $payload)->assertAccepted();
        $this->assertDatabaseCount('login_email_challenges', 2);
        $this->assertNotSame($first->json('login_poll_token'), $second->json('login_poll_token'));
        $this->travel(61)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $second->json('login_poll_token')])->assertOk();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $first->json('login_poll_token'), 'code' => $firstCode])
            ->assertOk()->assertJsonPath('approved', true);
        $this->assertDatabaseCount('login_email_challenges', 1);
    }

    public function test_password_proven_browser_resumes_only_its_own_challenge_without_another_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $payload = ['identifier' => $user->email, 'password' => 'password'];
        $first = $this->postJson('/api/sign-in', $payload)->assertAccepted();
        $before = LoginEmailChallenge::sole()->getAttributes();
        Notification::fake();
        $this->postJson('/api/sign-in', [...$payload, 'login_poll_token' => $first->json('login_poll_token')])
            ->assertAccepted()->assertJsonPath('login_poll_token', $first->json('login_poll_token'))
            ->assertJsonPath('email_delivery_queued', false);
        Notification::assertNothingSent();
        $this->assertSame($before, LoginEmailChallenge::sole()->getAttributes());
        $this->postJson('/api/sign-in', [...$payload, 'password' => 'wrong', 'login_poll_token' => $first->json('login_poll_token')])
            ->assertUnauthorized()->assertJsonMissingPath('login_poll_token');
    }

    public function test_five_source_failures_leave_another_source_challenge_usable(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'admin']);
        $payload = ['identifier' => $user->email, 'password' => 'password'];
        $first = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->postJson('/api/sign-in', $payload)->assertAccepted();
        $this->travel(61)->seconds();
        $second = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->postJson('/api/sign-in', $payload)->assertAccepted();
        $code = $this->loginCodeSentTo($user);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/email/login/confirm', ['poll_token' => $first->json('login_poll_token'), 'code' => 'wrong0'])
                ->assertUnprocessable(); // Malformed input does not spend an OTP guess.
            $this->postJson('/api/email/login/confirm', ['poll_token' => $first->json('login_poll_token'),
                'code' => $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000'])->assertStatus($i === 5 ? 429 : 422);
            $this->travel(7)->seconds();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson('/api/email/login/confirm', ['poll_token' => $second->json('login_poll_token'), 'code' => $code])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->postJson('/api/sign-in', $payload)->assertTooManyRequests();
    }

    public function test_login_resend_does_not_extend_the_original_deadline(): void
    {
        Notification::fake();
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $login = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $deadline = LoginEmailChallenge::sole()->expires_at;
        $this->assertTrue($deadline->equalTo(now()->addMinutes(10)));
        $this->travel(61)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $login->json('login_poll_token')])->assertOk();
        $this->assertTrue(LoginEmailChallenge::sole()->expires_at->equalTo($deadline));
    }

    use \Tests\Support\CreatesAuthenticationSchema;
    use \Tests\Support\ConcurrentAuthenticationRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticationSchema();
    }

    protected function tearDown(): void
    {
        $this->dropAuthenticationSchema();
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailDeliveryModes')]
    public function test_failed_initial_queueing_rolls_back_attempt_and_allows_retry(string $mode): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', $mode);
        DB::statement("CREATE TRIGGER reject_auth_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $this->registerInBrowser($this->registrationPayload())->assertStatus(503)->assertJsonPath('code', 'email_delivery_failed');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->app->terminate();
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        DB::statement('DROP TRIGGER reject_auth_job');
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertCount($mode === 'immediate' ? 1 : 0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_disabled_or_archived_accounts_cannot_verify(): void
    {
        foreach ([
            ['is_active' => false, 'is_archived' => false],
            ['is_active' => true, 'is_archived' => true],
        ] as $index => $state) {
            $plainToken = str_repeat((string) ($index + 1), 64);
            $user = User::factory()->unverified()->create([
                ...$state,
                'email_verification_token' => hash('sha256', $plainToken),
                'email_verification_expires_at' => now()->addHour(),
            ]);

            $this->postJson('/api/email/verify', ['token' => $plainToken])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'registration_link_invalid')
                ->assertJsonMissingPath('token');
            $this->assertNull($user->fresh()->email_verified_at);
        }
    }

    public function test_unverified_customers_cannot_sign_in_or_use_a_preexisting_token(): void
    {
        $user = User::factory()->unverified()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])
            ->assertForbidden()
            ->assertJsonPath('email_not_verified', true);

        $token = $user->createToken('auth_token')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verified_customer_login_requires_and_consumes_an_email_challenge(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'login-confirmation@gmail.com',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $response = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
            'remember' => false,
        ]);
        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'login_confirmation_required')
            ->assertJsonPath('requires_login_confirmation', true)
            ->assertJsonPath('email_delivery_queued', true)
            ->assertJsonStructure(['login_poll_token'])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $code = $this->loginCodeSentTo($user);
        $plainPollToken = $response->json('login_poll_token');
        $this->assertIsString($plainPollToken);
        $this->assertSame(64, strlen($plainPollToken));
        $challenge = LoginEmailChallenge::where('user_id', $user->user_id)->firstOrFail();
        $this->assertSame(
            hash_hmac('sha256', $code, $plainPollToken),
            $challenge->token_hash,
        );
        $this->assertNotSame($code, $challenge->token_hash);
        $this->assertSame(hash('sha256', $plainPollToken), $challenge->poll_token_hash);
        $this->assertNotSame($plainPollToken, $challenge->poll_token_hash);
        $this->assertFalse($challenge->remember_me);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $firstSessionResponse = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ]);
        $firstSessionResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('approved', true)
            ->assertJsonPath('session_established', true)
            ->assertJsonPath('remember_me', false)
            ->assertJsonPath('user.user_id', $user->user_id)
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $plainPollToken, 'code' => $code])
            ->assertUnprocessable()->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withAccountSession($firstSessionResponse)->getJson('/api/me')->assertOk();
    }

    public function test_login_confirmation_email_is_distinct_from_signup_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();

        Notification::assertSentTo(
            $user,
            ConfirmLoginNotification::class,
            function (ConfirmLoginNotification $notification) use ($user): bool {
                $message = $notification->toMail($user);

                $this->assertSame(
                    'Your Sign-In Code - Bethlehem Animal Clinic',
                    $message->subject,
                );
                $this->assertNull($message->actionText);
                $this->assertNull($message->actionUrl);
                $this->assertMatchesRegularExpression('/^\d{6}$/', $notification->code);
                $this->assertStringContainsString(
                    "**{$notification->code}**",
                    implode(' ', $message->introLines),
                );
                $this->assertMatchesRegularExpression(
                    '/<strong\b[^>]*>'.preg_quote($notification->code, '/').'<\/strong>/',
                    (string) $message->render(),
                );
                $this->assertStringNotContainsString(
                    'verify your email address',
                    strtolower(implode(' ', $message->introLines)),
                );

                return true;
            },
        );
    }

    public function test_incorrect_login_code_keeps_the_challenge_available(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $response = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();

        $correctCode = $this->loginCodeSentTo($user);
        $incorrectCode = $correctCode === '000000' ? '000001' : '000000';

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $response->json('login_poll_token'),
            'code' => $incorrectCode,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'login_code_invalid')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
            'approved_at' => null,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_code_is_bound_to_the_original_browser_token(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();
        $code = $this->loginCodeSentTo($user);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => str_repeat('x', 64),
            'code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'login_code_invalid')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
            'approved_at' => null,
        ]);
    }

    public function test_login_code_preserves_leading_zeroes(): void
    {
        $code = '012345';
        $plainPollToken = str_repeat('p', 64);
        $user = User::factory()->create();
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', $code, $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('session_established', true)
            ->assertJsonStructure(['token']);
    }

    public function test_login_code_is_locked_after_five_incorrect_attempts(): void
    {
        $plainPollToken = str_repeat('l', 64);
        $user = User::factory()->create();
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', '123456', $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->addMinutes(15),
        ]);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/email/login/confirm', [
                'poll_token' => $plainPollToken,
                'code' => '000000',
            ])->assertStatus(422);
        }

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => '000000',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'login_code_locked')
            ->assertJsonMissingPath('token');

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => '123456',
        ])->assertStatus(429);

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
            'approved_at' => null,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_login_confirmation_cannot_create_a_session(): void
    {
        $code = '123456';
        $plainPollToken = str_repeat('e', 64);
        $user = User::factory()->create();
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', $code, $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('expired', true)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disabled_customer_cannot_consume_a_login_confirmation(): void
    {
        $code = '654321';
        $plainPollToken = str_repeat('f', 64);
        $user = User::factory()->create(['is_active' => false]);
        LoginEmailChallenge::create([
            'user_id' => $user->user_id,
            'token_hash' => hash_hmac('sha256', $code, $plainPollToken),
            'poll_token_hash' => hash('sha256', $plainPollToken),
            'remember_me' => true,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_failed_login_confirmation_queueing_does_not_leave_a_challenge(): void
    {
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('Queue unavailable'));
        });

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_staff_username_login_requires_a_code_sent_to_the_staff_email(): void
    {
        Notification::fake();
        $staff = User::factory()->create([
            'first_name' => 'Grooming',
            'last_name' => 'Staff',
            'username' => 'groomingstaff',
            'email' => 'bethlehem.staff.test@gmail.com',
            'role' => 'staff',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $response = $this->postJson('/api/sign-in', [
            'identifier' => 'groomingstaff',
            'password' => 'strong-password',
        ]);
        $response
            ->assertAccepted()
            ->assertJsonPath('requires_login_confirmation', true)
            ->assertJsonPath('email', 'bethlehem.staff.test@gmail.com')
            ->assertJsonMissingPath('token');

        $code = $this->loginCodeSentTo($staff);
        Notification::assertSentTo(
            $staff,
            ConfirmLoginNotification::class,
            function (ConfirmLoginNotification $notification) use ($staff): bool {
                $message = $notification->toMail($staff);

                $this->assertSame(
                    'Staff Sign-In Code - Bethlehem Animal Clinic',
                    $message->subject,
                );
                $this->assertStringContainsString(
                    'staff account',
                    strtolower(implode(' ', $message->introLines)),
                );

                return true;
            },
        );

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $response->json('login_poll_token'),
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonPath('user.username', 'groomingstaff')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $staff->user_id,
            'name' => 'admin_token',
        ]);
    }

    public function test_admin_username_login_requires_a_code_sent_to_the_admin_email(): void
    {
        Notification::fake();
        $admin = User::factory()->create([
            'username' => 'Admin',
            'email' => 'bethlehem.admin.test@gmail.com',
            'role' => 'admin',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $response = $this->postJson('/api/sign-in', [
            'identifier' => 'Admin',
            'password' => 'strong-password',
        ]);
        $response
            ->assertAccepted()
            ->assertJsonPath('requires_login_confirmation', true)
            ->assertJsonPath('email', 'bethlehem.admin.test@gmail.com')
            ->assertJsonMissingPath('token');

        $code = $this->loginCodeSentTo($admin);
        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $response->json('login_poll_token'),
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.username', 'Admin')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->user_id,
            'name' => 'admin_token',
        ]);
    }

    public function test_sign_in_replaces_previous_session_and_old_logout_cannot_revoke_new_session(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'sessions@gmail.com',
            'password_hash' => Hash::make('strong-password'),
            'role' => 'staff',
        ]);

        $firstLogin = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();
        $firstToken = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $firstLogin->json('login_poll_token'),
            'code' => $this->loginCodeSentTo($user),
        ])->assertOk();
        $this->withAccountSession($firstToken)->postJson('/api/email/login/complete', [
            'poll_token' => $firstLogin->json('login_poll_token'),
        ])->assertOk();

        Notification::fake();
        $this->travel(61)->seconds();
        $secondLogin = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();
        $secondToken = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $secondLogin->json('login_poll_token'),
            'code' => $this->loginCodeSentTo($user),
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withAccountSession($firstToken)->postJson('/api/logout')->assertUnauthorized()->assertJsonPath('code', 'session_replaced');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->app['auth']->forgetGuards();
        $this->withAccountSession($firstToken)->getJson('/api/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withAccountSession($secondToken)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.user_id', $user->user_id);
    }

    public function test_account_state_is_rechecked_on_every_authenticated_request(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_sign_in_accepts_the_documented_plus_63_phone_format(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'phone' => '09171234567',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => '+63'.substr($user->phone, 1),
            'password' => 'strong-password',
        ])
            ->assertAccepted()
            ->assertJsonPath('code', 'login_confirmation_required')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('login_email_challenges', [
            'user_id' => $user->user_id,
        ]);
    }

    public function test_sign_in_throttling_cannot_globally_lock_out_an_identifier(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/sign-in', [
                'identifier' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->postJson('/api/sign-in', [
                'identifier' => $user->email,
                'password' => 'strong-password',
            ])
            ->assertAccepted()
            ->assertJsonPath('requires_login_confirmation', true);
    }

    public function test_sign_in_password_cooldowns_progress_immediately_and_blocked_requests_do_not_extend_them(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $payload = ['identifier' => $user->email, 'password' => 'wrong-password'];
        foreach ([60, 300, 900] as $duration) {
            foreach (range(1, 4) as $attempt) {
                $this->postJson('/api/sign-in', $payload)->assertUnauthorized()->assertHeaderMissing('Retry-After');
            }
            $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()
                ->assertJsonPath('code', 'sign_in_password_cooldown')->assertJsonPath('retry_after', $duration)
                ->assertHeader('Retry-After', (string) $duration)->assertHeader('Cache-Control', 'no-store, private');
            $this->travel($duration - 1)->seconds();
            foreach (range(1, 3) as $blocked) {
                // No cookie or browser state is required for server enforcement.
                $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()
                    ->assertJsonPath('retry_after', 1)->assertHeader('Retry-After', '1');
            }
            $this->travel(1)->seconds();
        }
        // The full 15-minute cooldown is also 15 minutes without a checked failure.
        foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('retry_after', 60);
    }

    public function test_sign_in_escalation_resets_after_exactly_fifteen_minutes_without_a_failure(): void
    {
        $this->freezeTime();
        $payload = ['identifier' => User::factory()->create()->email, 'password' => 'wrong-password'];
        foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $this->postJson('/api/sign-in', $payload)->assertTooManyRequests();
        $this->travel(899)->seconds();
        $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $this->travel(900)->seconds();
        foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('retry_after', 60);
    }

    public function test_verified_password_resets_pair_state_even_when_login_email_is_rate_limited(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $payload = ['identifier' => $user->email, 'password' => 'wrong-password'];
        foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $this->postJson('/api/sign-in', $payload)->assertTooManyRequests();
        $this->travel(60)->seconds();
        $this->postJson('/api/sign-in', [...$payload, 'password' => 'strong-password'])->assertAccepted();
        foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $this->postJson('/api/sign-in', [...$payload, 'password' => 'strong-password'])
            ->assertTooManyRequests()->assertJsonPath('code', 'email_cooldown');
        foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('retry_after', 60);
    }

    public function test_shared_ip_budget_survives_success_and_does_not_escalate_other_identifiers(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        foreach (range(1, 5) as $account) {
            foreach (range(1, 4) as $attempt) {
                $this->postJson('/api/sign-in', ['identifier' => "missing-$account", 'password' => 'wrong'])
                    ->assertUnauthorized();
            }
        }
        $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/sign-in', ['identifier' => 'another-missing', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/sign-in', ['identifier' => 'another-missing', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertJsonPath('code', 'sign_in_ip_cooldown')->assertJsonPath('retry_after', 60);
        $this->postJson('/api/sign-in', ['identifier' => 'other-staff', 'password' => 'correct'])
            ->assertTooManyRequests()->assertJsonPath('code', 'sign_in_ip_cooldown');
        $this->travel(60)->seconds();
        $this->postJson('/api/sign-in', ['identifier' => 'other-staff', 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_phone_formats_share_the_same_password_cooldown(): void
    {
        $user = User::factory()->create(['phone' => '09171234567']);
        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/sign-in', ['identifier' => $user->phone, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/sign-in', ['identifier' => '+639171234567', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertJsonPath('retry_after', 60);
    }

    public function test_long_email_quota_is_not_a_password_cooldown_and_keeps_password_errors_generic(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $history = array_fill(0, 12, now()->timestamp - 43200);
        Cache::put('auth-email:destination:'.hash('sha256', strtolower($user->email)), $history, 86400);
        $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])
            ->assertTooManyRequests()->assertJsonPath('code', 'email_rate_limited')
            ->assertJsonPath('retry_after', 43200)->assertHeader('Retry-After', '43200');
        foreach ([$user->email, 'missing-account'] as $identifier) {
            $this->postJson('/api/sign-in', ['identifier' => $identifier, 'password' => 'wrong'])
                ->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
        }
    }

    public function test_pair_cooldown_is_not_misclassified_as_a_long_shared_ip_restriction(): void
    {
        $this->freezeTime();
        $payload = ['identifier' => User::factory()->create()->email, 'password' => 'wrong'];
        foreach ([60, 300] as $duration) {
            foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
            $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('retry_after', $duration);
            if ($duration === 60) $this->travel(60)->seconds();
        }
        $ipKey = 'sign-in-ip:'.hash('sha256', '127.0.0.1');
        foreach (range(1, 20) as $attempt) RateLimiter::hit($ipKey, 60);
        $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()
            ->assertJsonPath('code', 'sign_in_password_cooldown')->assertJsonPath('retry_after', 300);
        $this->postJson('/api/sign-in', ['identifier' => 'different-staff', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertJsonPath('code', 'sign_in_ip_cooldown')->assertJsonPath('retry_after', 60);
    }

    public function test_password_cooldown_uses_persistent_database_cache_and_serializes_contending_attempts(): void
    {
        $migration = require base_path('database/migrations/0001_01_01_000001_create_cache_table.php');
        $migration->up();
        config(['cache.default' => 'database']);
        $this->app->forgetInstance(\Illuminate\Cache\RateLimiter::class);
        RateLimiter::clearResolvedInstance('cache.rate_limiter');
        $this->freezeTime();
        $user = User::factory()->create();
        $payload = ['identifier' => $user->email, 'password' => 'wrong'];
        $key = 'sign-in-password:'.hash('sha256', strtolower($user->email).'|127.0.0.1');
        $lock = Cache::lock($key.':lock', 30);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('code', 'sign_in_busy');
            $this->assertNull(Cache::get($key), 'A contending request must not mutate password counters.');
        } finally {
            $lock->release();
        }
        try {
            foreach ([60, 300, 900] as $duration) {
                foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
                $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('retry_after', $duration);
                // Recreate cache repositories as another PHP request would.
                app('cache')->forgetDriver('database');
                $this->postJson('/api/sign-in', $payload)->assertTooManyRequests()->assertJsonPath('retry_after', $duration);
                $this->travel($duration)->seconds();
            }
            $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        } finally {
            $migration->down();
        }
    }

    public static function loginResendFailureStates(): array
    {
        return ['usable code' => [false], 'exhausted code' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loginResendFailureStates')]
    public function test_login_resend_failure_preserves_the_previous_challenge(bool $exhausted): void
    {
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', [
            'identifier' => $user->email, 'password' => 'strong-password',
        ])->assertAccepted();
        $code = $this->loginCodeSentTo($user);
        if ($exhausted) {
            $wrong = $code === '000000' ? '111111' : '000000';
            foreach (range(1, 5) as $attempt) {
                $this->postJson('/api/email/login/confirm', ['poll_token' => $response->json('login_poll_token'), 'code' => $wrong])
                    ->assertStatus($attempt === 5 ? 429 : 422);
            }
        }
        $previous = LoginEmailChallenge::firstOrFail()->getAttributes();
        $this->travel(61)->seconds();
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->once()
                ->andThrow(new \RuntimeException('SMTP secret must not reach the browser'));
        });

        $this->postJson('/api/email/login/resend', ['poll_token' => $response->json('login_poll_token')])
            ->assertStatus(503)->assertJsonPath('success', false)
            ->assertDontSee('SMTP secret');
        $this->assertSame($previous, LoginEmailChallenge::firstOrFail()->getAttributes());
        $confirmation = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $response->json('login_poll_token'), 'code' => $code,
        ]);
        if ($exhausted) {
            $confirmation->assertUnprocessable()->assertJsonPath('code', 'login_code_exhausted');
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $this->assertSame(5, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        } else $confirmation->assertOk();
    }

    public function test_login_resend_rotates_the_code_and_only_resets_the_issued_code_allowance(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', [
            'identifier' => $user->email, 'password' => 'strong-password',
        ])->assertAccepted();
        $pollToken = $response->json('login_poll_token');
        $oldCode = $this->loginCodeSentTo($user);
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/email/login/confirm', [
                'poll_token' => $pollToken, 'code' => $oldCode === '000000' ? '000001' : '000000',
            ])->assertStatus(422);
        }
        Notification::fake();
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->postJson('/api/email/login/resend', ['poll_token' => $pollToken])->assertOk();
        $newCode = $this->loginCodeSentTo($user);
        $this->assertNotSame($oldCode, $newCode);
        $this->assertSame(0, LoginEmailChallenge::sole()->failed_attempts);
        $this->assertSame(3, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        $hash = LoginEmailChallenge::query()->sole()->token_hash;
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson('/api/email/login/resend', ['poll_token' => $pollToken])->assertTooManyRequests();
        $this->assertSame($hash, LoginEmailChallenge::query()->sole()->token_hash);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pollToken, 'code' => $oldCode])->assertStatus(422);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pollToken, 'code' => $oldCode])->assertStatus(422);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pollToken, 'code' => $newCode])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_progressive_otp_recovery_preserves_the_deadline_and_cumulative_budgets(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $user = User::factory()->create();
        $credentials = ['identifier' => $user->email, 'password' => 'password'];
        $login = $this->postJson('/api/sign-in', $credentials)->assertAccepted()->assertJsonPath('expires_in', 600);
        $token = $login->json('login_poll_token');
        $deadline = LoginEmailChallenge::sole()->expires_at;

        foreach ([60, 180, 300] as $round => $seconds) {
            $code = $this->loginCodeSentTo($user);
            $wrong = $code === '000000' ? '111111' : '000000';
            foreach (range(1, 5) as $attempt) {
                $response = $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $wrong]);
                if ($attempt < 5) $response->assertUnprocessable()->assertJsonPath('message', 'Incorrect code. Try again.');
                else $response->assertTooManyRequests()->assertJsonPath('code', 'login_code_locked')
                    ->assertJsonPath('retry_after', $seconds)->assertHeader('Retry-After', (string) $seconds);
            }
            $this->assertSame(5, LoginEmailChallenge::sole()->failed_attempts);
            Notification::fake();
            $this->travel(20)->seconds();
            $this->postJson('/api/sign-in', [...$credentials, 'login_poll_token' => $token])->assertTooManyRequests()
                ->assertJsonPath('code', 'login_code_locked')->assertJsonPath('retry_after', $seconds - 20);
            $this->postJson('/api/email/login/resend', ['poll_token' => $token])->assertTooManyRequests()
                ->assertJsonPath('retry_after', $seconds - 20);
            $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $code])->assertTooManyRequests()
                ->assertJsonPath('retry_after', $seconds - 20);
            Notification::assertNothingSent();
            $this->assertSame(($round + 1) * 5, RateLimiter::attempts('login-code-account:'.$user->getKey()));
            $this->travel($seconds - 20)->seconds();
            $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $code])->assertUnprocessable()
                ->assertJsonPath('code', 'login_code_exhausted')->assertJsonPath('requires_new_code', true);
            $this->assertDatabaseCount('personal_access_tokens', 0);
            if ($round < 2) {
                $resume = $this->postJson('/api/sign-in', [...$credentials, 'login_poll_token' => $token])->assertAccepted()
                    ->assertJsonPath('requires_new_code', true)->assertJsonPath('email_delivery_queued', false);
                $this->assertSame($deadline->timestamp - now()->timestamp, $resume->json('expires_in'));
                $this->postJson('/api/email/login/resend', ['poll_token' => $token])->assertOk();
                $this->assertNotSame($code, $this->loginCodeSentTo($user));
                $this->assertSame(0, LoginEmailChallenge::sole()->failed_attempts);
                $this->assertTrue($deadline->equalTo(LoginEmailChallenge::sole()->expires_at));
            }
        }
        // The fourth email is still subject to the unchanged rolling quota.
        $this->postJson('/api/email/login/resend', ['poll_token' => $token])->assertTooManyRequests()
            ->assertJsonPath('code', 'email_rate_limited');
        $this->assertSame(5, LoginEmailChallenge::sole()->failed_attempts);
        $this->travel(60)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $token])->assertUnprocessable()
            ->assertJsonPath('expired', true)->assertJsonPath('message', 'Sign-in expired. Please sign in again.');
    }

    public function test_source_escalation_caps_at_five_minutes_and_resets_only_after_inactivity(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        // Isolate source escalation from the separately tested stronger budgets/quotas.
        config(['authentication.otp_account_attempts' => 100, 'authentication.otp_ip_attempts' => 100,
            'authentication.email.quarter_hour' => 30, 'authentication.email.hour' => 30,
            'authentication.email.day' => 30, 'authentication.email.purposes.login.quarter_hour' => 30]);
        $user = User::factory()->create();
        $credentials = ['identifier' => $user->email, 'password' => 'password'];
        foreach ([60, 180, 300, 300, 300] as $seconds) {
            $login = $this->postJson('/api/sign-in', $credentials)->assertAccepted();
            $wrong = $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000';
            foreach (range(1, 5) as $attempt) {
                $response = $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $wrong]);
                if ($attempt < 5) $response->assertUnprocessable();
                else $response->assertTooManyRequests()->assertJsonPath('retry_after', $seconds);
            }
            $this->travel($seconds)->seconds();
        }
        $this->travel(1800)->seconds();
        $login = $this->postJson('/api/sign-in', $credentials)->assertAccepted();
        $wrong = $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $attempt) {
            $response = $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $wrong]);
        }
        $response->assertTooManyRequests()->assertJsonPath('retry_after', 60);
    }

    public function test_replacement_near_expiry_cannot_extend_the_password_confirmed_transaction(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $user = User::factory()->create();
        $login = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertAccepted();
        $deadline = LoginEmailChallenge::sole()->expires_at;
        $this->travel(590)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $login->json('login_poll_token')])->assertOk()
            ->assertJsonPath('expires_in', 10);
        $this->assertTrue($deadline->equalTo(LoginEmailChallenge::sole()->expires_at));
        $this->travel(10)->seconds();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $this->loginCodeSentTo($user)])
            ->assertUnprocessable()->assertJsonPath('expired', true)->assertJsonPath('message', 'Sign-in expired. Please sign in again.');
        $this->postJson('/api/email/login/resend', ['poll_token' => $login->json('login_poll_token')])->assertUnprocessable()->assertJsonPath('expired', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_source_partial_rounds_survive_resend_and_success_resets_only_the_current_source_history(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $credentials = ['identifier' => $user->email, 'password' => 'password'];
        $login = $this->postJson('/api/sign-in', $credentials)->assertAccepted();
        $token = $login->json('login_poll_token');
        $wrong = $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000';
        foreach (range(1, 3) as $attempt) $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $wrong])->assertUnprocessable();
        $this->travel(60)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $token])->assertOk();
        $wrong = $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000';
        $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $wrong])->assertUnprocessable();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $wrong])->assertTooManyRequests()->assertJsonPath('retry_after', 60);
        $this->assertSame(2, LoginEmailChallenge::sole()->failed_attempts, 'Source history spans replacement codes.');
        $this->travel(60)->seconds();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => $this->loginCodeSentTo($user)])->assertOk();
        $ipKey = 'login-code-ip:'.hash('sha256', '127.0.0.1');
        $this->assertSame(5, RateLimiter::attempts($ipKey));
        $this->assertSame(0, RateLimiter::attempts('login-code-account:'.$user->getKey()));
        $login = $this->postJson('/api/sign-in', $credentials)->assertAccepted();
        $wrong = $this->loginCodeSentTo($user) === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $attempt) $response = $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $wrong]);
        $response->assertTooManyRequests()->assertJsonPath('retry_after', 60);
    }

    public function test_twenty_five_otp_failures_across_accounts_enforce_the_independent_ip_budget(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        foreach (range(1, 5) as $account) {
            if ($account > 1) $this->travel(61)->seconds(); // Stay below the independent route flood throttle.
            $user = User::factory()->create();
            $token = str_pad((string) $account, 64, 'x');
            LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', $token),
                'token_hash' => hash_hmac('sha256', '123456', $token), 'expires_at' => now()->addMinutes(10)]);
            foreach (range(1, 5) as $attempt) $response = $this->postJson('/api/email/login/confirm', ['poll_token' => $token, 'code' => '000000']);
            $response->assertTooManyRequests()->assertJsonPath('retry_after', $account === 5 ? 656 : 60);
        }
        $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'password'])->assertTooManyRequests()->assertJsonPath('retry_after', 656);
        $this->postJson('/api/email/login/resend', ['poll_token' => $token])->assertTooManyRequests()->assertJsonPath('retry_after', 656);
        $this->assertSame(25, RateLimiter::attempts('login-code-ip:'.hash('sha256', '127.0.0.1')));
        Notification::assertNotSentTo($user, ConfirmLoginNotification::class);
    }

    public function test_login_email_requests_are_throttled_before_creating_another_challenge(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        foreach (range(1, 3) as $attempt) {
            $this->travel(61)->seconds();
            $this->postJson('/api/sign-in', [
                'identifier' => $user->email, 'password' => 'strong-password',
            ])->assertAccepted();
        }
        $hash = LoginEmailChallenge::firstOrFail()->token_hash;
        $this->postJson('/api/sign-in', [
            'identifier' => $user->email, 'password' => 'strong-password',
        ])->assertStatus(429)->assertHeader('Retry-After')->assertJsonMissingPath('token');
        $this->assertSame($hash, LoginEmailChallenge::firstOrFail()->token_hash);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailDeliveryModes')]
    public function test_authentication_delivery_sends_one_otp_for_every_login_role(string $mode): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', $mode);
        foreach (['customer', 'staff', 'admin'] as $index => $role) {
            $user = User::factory()->create(['role' => $role, 'password_hash' => Hash::make('strong-password')]);
            $response = $this->postJson('/api/sign-in', [
                'identifier' => $user->email, 'password' => 'strong-password',
            ])->assertAccepted()->assertJsonMissingPath('token');
            $this->assertSame(0, $user->tokens()->count());
            $this->assertCount($index + ($mode === 'immediate' ? 1 : 0), app('mailer')->getSymfonyTransport()->messages());
            $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
            $this->assertCount($index + 1, app('mailer')->getSymfonyTransport()->messages());
            $email = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $this->assertSame($user->email, $email->getTo()[0]->getAddress());
            preg_match('/\b[0-9]{6}\b/', $email->getTextBody(), $matches);
            $this->postJson('/api/email/delivery-status', ['delivery_token' => $response->json('email_delivery_token')])
                ->assertOk()->assertJsonPath('status', 'sent');
            $this->postJson('/api/email/login/confirm', [
                'poll_token' => $response->json('login_poll_token'), 'code' => $matches[0],
            ])->assertOk()->assertJsonPath('user.role', $role)->assertJsonStructure(['token']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailDeliveryModes')]
    public function test_transport_failure_is_reported_without_exposing_secrets_and_can_retry(string $mode): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', $mode);
        app('mail.manager')->extend('unavailable', fn () => new class extends \Symfony\Component\Mailer\Transport\AbstractTransport {
            protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('Private SMTP credentials');
            }
            public function __toString(): string { return 'unavailable'; }
        });
        config()->set('mail.mailers.unavailable', ['transport' => 'unavailable']);
        config()->set('mail.default', 'unavailable');
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $receipt = $this->privateDeliveryReceipt();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($mode === 'immediate' ? 'retrying' : 'queued', \App\Services\AuthenticationMailDelivery::status($receipt));
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'retrying')->assertDontSee('Private SMTP');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('jobs', 1);
        config()->set('mail.default', 'array');
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public static function mailDeliveryModes(): array
    {
        return ['queued' => ['queued'], 'immediate' => ['immediate']];
    }

    public function test_production_cannot_report_log_failover_as_delivered_login_mail(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $this->app->instance('env', 'production');
        config()->set('app.frontend_url', 'https://clinic.example.test');
        config()->set('mail.from.address', 'clinic@gmail.com');
        config()->set('mail.default', 'failover');
        $this->postJson('/api/sign-in', [
            'identifier' => $user->email, 'password' => 'strong-password',
        ])->assertStatus(503)->assertJsonMissingPath('token');
        $this->assertDatabaseCount('login_email_challenges', 0);
    }

    public function test_initial_registration_delivers_after_outer_commit_and_termination_without_duplicate_mail(): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', 'immediate');
        DB::beginTransaction();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertDatabaseCount('jobs', 1);
        $command = json_decode(DB::table('jobs')->value('payload'), true)['data']['command'];
        $job = unserialize(app('encrypter')->decrypt($command));
        $this->assertInstanceOf(VerifyEmailNotification::class, $job->notification);
        $this->assertStringNotContainsString($job->notification->token, $command);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $receipt = $this->privateDeliveryReceipt();
        $this->app->terminate();
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        DB::commit();
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->app->terminate();
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame('database', $job->notification->connection);
        $this->assertSame('sent', \App\Services\AuthenticationMailDelivery::status($receipt));
        $this->assertDatabaseCount('jobs', 1);
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_rolled_back_initial_registration_never_sends_at_termination(): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', 'immediate');
        DB::beginTransaction();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertDatabaseCount('jobs', 1);
        DB::rollBack();
        $this->app->terminate();
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_replaced_login_job_does_not_send_but_current_code_authenticates(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $this->travel(61)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $response->json('login_poll_token')])->assertOk();
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        preg_match('/\b[0-9]{6}\b/', $messages[0]->getOriginalMessage()->getTextBody(), $matches);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $response->json('login_poll_token'), 'code' => $matches[0]])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_resend_cooldown_is_enforced_on_the_server(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $hash = LoginEmailChallenge::firstOrFail()->token_hash;
        $this->travel(32)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $response->json('login_poll_token')])
            ->assertTooManyRequests()->assertHeader('Retry-After', '28')->assertJsonPath('retry_after', 28);
        $this->assertSame($hash, LoginEmailChallenge::firstOrFail()->token_hash);
        $this->travel(28)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $response->json('login_poll_token')])
            ->assertOk()->assertJsonPath('resend_after', 60);
    }

    public function test_immediate_transport_failure_falls_back_to_encrypted_retries_until_terminal_failure(): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', 'immediate');
        app('mail.manager')->extend('unavailable', fn () => new class extends \Symfony\Component\Mailer\Transport\AbstractTransport {
            protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('Private SMTP credentials');
            }
            public function __toString(): string { return 'unavailable'; }
        });
        config()->set('mail.mailers.unavailable', ['transport' => 'unavailable']);
        config()->set('mail.default', 'unavailable');
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('VerifyEmailNotification', json_decode($payload, true)['data']['command']);
        $receipt = $this->privateDeliveryReceipt();
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
                ->assertOk()->assertJsonPath('status', 'retrying')->assertDontSee('Private SMTP');
            DB::table('jobs')->update(['available_at' => now()->timestamp]);
            $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'failed');
        $this->assertDatabaseCount('users', 0);
    }

    private function useDatabaseMailQueue(): void
    {
        (require base_path('database/migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
    }


    public function test_a_competing_worker_releases_the_job_without_duplicate_sending(): void
    {
        $this->useDatabaseMailQueue();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $receipt = $this->privateDeliveryReceipt();
        $lock = \Illuminate\Support\Facades\Cache::lock('auth-mail-send:'.hash('sha256', $receipt), 60);
        $this->assertTrue($lock->get());
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 1);
        $lock->release();
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_consumed_login_issuance_cannot_be_resent_or_mailed(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $challenge = LoginEmailChallenge::query()->sole();
        $challenge->update(['approved_at' => now()]);
        $this->travel(61)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $response->json('login_poll_token')])->assertUnprocessable();
        $this->assertSame($challenge->token_hash, $challenge->fresh()->token_hash);
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_receipt_only_legacy_otp_jobs_are_suppressed(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create();
        $notification = new ConfirmLoginNotification('123456');
        $notification->deliveryReceipt = str_repeat('r', 64);
        $notification->through([new \App\Jobs\Middleware\TrackAuthenticationMailDelivery($notification->deliveryReceipt)]);
        $user->notify($notification);
        $this->workQueue(['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame('superseded', \App\Services\AuthenticationMailDelivery::status($notification->deliveryReceipt));
    }


    public function test_login_queue_insert_failure_cannot_commit_a_replacement(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $before = LoginEmailChallenge::query()->sole()->getAttributes();
        $this->travel(61)->seconds();
        DB::statement("CREATE TRIGGER reject_auth_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $this->postJson('/api/email/login/resend', ['poll_token' => $response->json('login_poll_token')])->assertStatus(503);
        $this->assertSame($before, LoginEmailChallenge::query()->sole()->getAttributes());
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_legacy_email_still_enters_the_existing_sign_in_challenge(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'ab@legacy.example', 'password_hash' => Hash::make('strong-password')]);
        $this->postJson('/api/sign-in', ['identifier' => 'ab@legacy.example', 'password' => 'strong-password'])
            ->assertAccepted()->assertJsonPath('requires_login_confirmation', true);
    }

    public function test_restart_and_ip_change_do_not_reset_the_account_otp_budget(): void
    {
        config(['authentication.otp_account_attempts' => 5]); // Verify a stricter configured global budget too.
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $login = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $wrong = $this->loginCodeSentTo($user) === '000000' ? '000001' : '000000';
        foreach (range(1, 4) as $i) $this->postJson('/api/email/login/confirm', ['poll_token' => $login->json('login_poll_token'), 'code' => $wrong])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.80']);
        $fresh = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $wrong = $this->loginCodeSentTo($user) === '000000' ? '000001' : '000000';
        $this->postJson('/api/email/login/confirm', ['poll_token' => $fresh->json('login_poll_token'), 'code' => $wrong])
            ->assertTooManyRequests()->assertJsonPath('code', 'login_code_locked');
        $this->postJson('/api/email/login/resend', ['poll_token' => $fresh->json('login_poll_token')])->assertTooManyRequests();
        $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertTooManyRequests();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->travel(901)->seconds();
        $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
    }

    public function test_failed_bound_resend_preserves_issuance_and_retried_old_mail_cannot_replace_new_link(): void
    {
        $this->useDatabaseMailQueue();
        $registration = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $before = $pending->getAttributes();
        $oldReceipt = $registration->json('email_delivery_token');
        $this->travel(61)->seconds();
        DB::statement("CREATE TRIGGER reject_registration_mail BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $this->postJson('/api/email/resend', ['registration_token' => $registration->json('registration_token')])->assertStatus(503);
        $this->assertSame($before, $pending->fresh()->getAttributes());
        DB::statement('DROP TRIGGER reject_registration_mail');
        $resend = $this->postJson('/api/email/resend', ['registration_token' => $registration->json('registration_token')])->assertOk();
        $newHash = $pending->fresh()->email_verification_token;
        $this->assertNotSame($before['email_verification_token'], $newHash);
        $this->workQueue(['--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertSame($newHash, $pending->fresh()->email_verification_token);
        $this->assertSame('superseded', \App\Services\AuthenticationMailDelivery::status($oldReceipt));
        $this->assertSame('sent', \App\Services\AuthenticationMailDelivery::status($resend->json('email_delivery_token')));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('users', 0);
    }

    public static function obsoleteRegistrationMailStates(): array
    {
        return [['approved'], ['expired'], ['cancelled']];
    }

    public function test_stale_immediate_recipient_snapshot_cannot_send_an_approved_registration_link(): void
    {
        $this->useDatabaseMailQueue();
        $registration = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $stale = PendingCustomerRegistration::sole();
        $command = json_decode(DB::table('jobs')->sole()->payload, true)['data']['command'];
        $queued = unserialize(app('encrypter')->decrypt($command));
        $notification = $queued->notification;
        $this->postJson('/api/email/verify', ['token' => $notification->token])->assertOk();
        // Immediate delivery retains its issued recipient object across commit;
        // the middleware must consult the current row, not that stale snapshot.
        $tracking = new \App\Jobs\Middleware\TrackAuthenticationMailDelivery($registration->json('email_delivery_token'), [
            'model' => PendingCustomerRegistration::class, 'key' => $stale->getKey(),
            'hash_column' => 'email_verification_token', 'hash' => $stale->email_verification_token,
            'expiry_column' => 'email_verification_expires_at',
        ]);
        $tracking->handle(new \Illuminate\Notifications\SendQueuedNotifications($stale, $notification, ['mail']),
            fn () => $stale->notifyNow($notification));
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame('superseded', \App\Services\AuthenticationMailDelivery::status($registration->json('email_delivery_token')));
        $this->assertNotNull($stale->fresh()->registration_approved_at);
        $this->assertDatabaseCount('users', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('obsoleteRegistrationMailStates')]
    public function test_obsolete_registration_jobs_cannot_send_or_create_accounts(string $state): void
    {
        $this->useDatabaseMailQueue();
        $registration = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $command = json_decode(DB::table('jobs')->sole()->payload, true)['data']['command'];
        $job = unserialize(app('encrypter')->decrypt($command));
        $this->assertInstanceOf(VerifyEmailNotification::class, $job->notification);
        $this->assertStringNotContainsString($job->notification->token, $command);
        $serialized = serialize($job);
        foreach (['strong-password', 'password_hash', 'first_name', 'last_name', '09123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        if ($state === 'approved') $this->postJson('/api/email/verify', ['token' => $job->notification->token])->assertOk();
        if ($state === 'expired') $this->travel(60)->minutes();
        if ($state === 'cancelled') $this->postJson('/api/register/cancel', ['registration_token' => $registration->json('registration_token')])->assertOk();
        $this->workQueue(['--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private string $lastPrivateReceipt = '';

    private function privateDeliveryReceipt(): string
    {
        if (! DB::table('jobs')->exists()) return $this->lastPrivateReceipt;
        $command = json_decode(DB::table('jobs')->orderBy('id')->value('payload'), true)['data']['command'];
        $job = unserialize(app('encrypter')->decrypt($command));
        return $this->lastPrivateReceipt = $job->notification->deliveryReceipt;
    }

    private function registerInBrowser(array $payload)
    {
        $response = $this->postJson('/api/register', [...$payload, 'registration_token' => \Illuminate\Support\Str::random(64)]);
        if ($cookie = $response->getCookie('bethlehem_registration_browser', false)) {
            $this->withUnencryptedCookie('bethlehem_registration_browser', $cookie->getValue())->withCredentials();
        }
        return $response;
    }

    private function registrationPayload(): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Customer',
            'username' => 'newcustomer',
            'phone' => '09123456789',
            'email' => 'new.customer@GMAIL.COM',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
            'terms_accepted' => true,
        ];
    }

    private function verificationTokenSentTo(User|PendingCustomerRegistration $notifiable): string
    {
        $notifications = Notification::sent($notifiable, VerifyEmailNotification::class);
        $this->assertNotEmpty($notifications);
        $token = $notifications->last()->token;
        $this->assertSame(64, strlen($token));
        return $token;
    }

    private function loginCodeSentTo(User $user): string
    {
        $code = null;

        Notification::assertSentTo(
            $user,
            ConfirmLoginNotification::class,
            function (ConfirmLoginNotification $notification) use (&$code) {
                $code = $notification->code;

                return true;
            },
        );

        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        return $code;
    }
    public function test_overlapping_login_requests_consume_only_one_issuance_on_sqlite(): void
    {
        Notification::fake();
            $user = User::factory()->create();
            $token = str_repeat('a', 64);
            LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', $token),
                'token_hash' => hash_hmac('sha256', '123456', $token), 'expires_at' => now()->addMinutes(10)]);
            $payload = json_encode(['poll_token' => $token, 'code' => '123456', '_verification_endpoint' => '/api/email/login/confirm'], JSON_THROW_ON_ERROR);
        $parameters=json_decode($payload,true,flags:JSON_THROW_ON_ERROR);
        $this->assertConcurrentAuthenticationRequests($parameters,$parameters,422,0);
    }
    public function test_signup_requires_email(): void
    {
        foreach ([null, '', 'invalid-email'] as $email) {
            $payload = $this->registrationPayload();
            $payload['email'] = $email;
            $this->registerInBrowser($payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
    }

    public function test_unsupported_email_is_rejected_before_pending_registration_or_otp(): void
    {
        Notification::fake();
        $this->registerInBrowser([...$this->registrationPayload(), 'email' => '123@123.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'Use Gmail, Outlook, Hotmail, Live, Yahoo, or iCloud.');
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_duplicate_signup_mobile_does_not_queue_verification_mail(): void
    {
        $this->useDatabaseMailQueue();
        User::factory()->create(['phone' => '09123456789']);

        $this->registerInBrowser($this->registrationPayload())->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_log_mailer_cannot_record_signup_tokens(): void
    {
        $this->useDatabaseMailQueue();
        Notification::fake();
        foreach (['log', 'failover'] as $mailer) {
            config()->set('mail.default', $mailer);
            $this->registerInBrowser($this->registrationPayload())->assertStatus(503)
                ->assertJsonMissingPath('email_delivery_queued')->assertJsonMissingPath('email_delivery_token');
            Notification::assertNothingSent();
            $this->assertDatabaseCount('pending_customer_registrations', 0);
            $this->assertDatabaseCount('users', 0);
            DB::table('jobs')->delete();
            $this->travel(61)->seconds();
        }
    }

    public static function phoneVerificationStatuses(): array
    {
        return [
            'missing' => [null, null, 'not_added'],
            'missing with stale timestamp' => [null, '2026-10-20 00:00:00', 'not_added'],
            'empty with stale timestamp' => ['', '2026-10-20 00:00:00', 'not_added'],
            'unverified' => ['09171234567', null, 'unverified'],
            'verified' => ['09171234567', '2026-10-20 00:00:00', 'verified'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phoneVerificationStatuses')]
    public function test_me_derives_phone_status_from_the_stored_phone_and_verification_timestamp(?string $phone, ?string $verifiedAt, string $expected): void
    {
        $user = User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => $verifiedAt,
            'email_verified_at' => now(),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.phone_verification_status', $expected)
            ->assertJsonMissingPath('user.phone_verified_at');
        $this->assertSame($phone, $user->fresh()->phone);
    }

    public function test_nullable_phone_migration_preserves_records_and_unique_indexes(): void
    {
        // Exercise historical DDL against its own pre-refactor schema.
        (require database_path('migrations/2026_11_07_000001_make_customer_registration_verify_first.php'))->down();
        foreach (['users', 'pending_customer_registrations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('phone', 20)->nullable(false)->change());
        }
        $user = User::factory()->create(['phone' => '09170000001']);
        $pending = PendingCustomerRegistration::forceCreate([
            'first_name' => 'Pending', 'last_name' => 'Customer', 'phone' => '09170000002',
            'email' => 'pending@gmail.com', 'password_hash' => Hash::make('password'),
        ]);
        $migration = require base_path('database/migrations/2026_10_21_000001_make_customer_phone_nullable.php');
        $migration->up();
        $this->assertSame('09170000001', $user->fresh()->phone);
        $this->assertSame('09170000002', $pending->fresh()->phone);
        foreach (['users', 'pending_customer_registrations'] as $name) {
            $this->assertTrue(Schema::hasIndex($name, ['phone'], 'unique'));
            $this->assertTrue(Schema::hasIndex($name, ['email'], 'unique'));
            foreach (['one', 'two'] as $suffix) {
                DB::table($name)->insert([
                    'first_name' => 'Optional', 'last_name' => 'Customer', 'phone' => null,
                    'email' => "$name-$suffix@gmail.com", 'password_hash' => 'unchanged-hash',
                ]);
            }
            try {
                DB::table($name)->insert([
                    'first_name' => 'Duplicate', 'last_name' => 'Customer',
                    'phone' => $name === 'users' ? '09170000001' : '09170000002',
                    'email' => "$name-duplicate@gmail.com", 'password_hash' => 'unchanged-hash',
                ]);
                $this->fail('Non-null phone uniqueness must remain enforced.');
            } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
                $this->assertStringContainsString('phone', $exception->getMessage());
            }
        }
        DB::table('users')->whereNull('phone')->delete();
        try {
            $migration->down();
            $this->fail('Rollback must not destroy phone-less records.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('NULL values exist', $exception->getMessage());
        }
        foreach (['users', 'pending_customer_registrations'] as $name) {
            $this->assertTrue(collect(Schema::getColumns($name))->firstWhere('name', 'phone')['nullable']);
            DB::table($name)->whereNull('phone')->delete();
        }
        $migration->down();
        foreach (['users', 'pending_customer_registrations'] as $name) {
            $this->assertFalse(collect(Schema::getColumns($name))->firstWhere('name', 'phone')['nullable']);
        }
    }

    public function test_registration_migration_resumes_partial_schema_and_preserves_data(): void
    {
        // Exercise historical DDL against its own pre-refactor schema.
        (require database_path('migrations/2026_11_07_000001_make_customer_registration_verify_first.php'))->down();
        $migration = require base_path('database/migrations/2026_10_05_000001_add_registration_verification_codes.php');
        // Match MySQL's state after the original index-name failure: the new
        // verification fields exist, but the unique index and phone field do not.
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->dropUnique('pending_registration_edit_token_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
        $pending = PendingCustomerRegistration::forceCreate([
            'first_name' => 'Pending',
            'last_name' => 'Customer',
            'phone' => '09123456789',
            'email' => 'pending@gmail.com',
            'password_hash' => Hash::make('strong-password'),
            'email_verification_code_hash' => Hash::make('123456'),
            'email_verification_attempts' => 2,
            'registration_edit_token_hash' => hash('sha256', 'existing-edit-credential'),
        ]);
        $user = User::factory()->create();
        $pendingBefore = $pending->fresh()->getAttributes();
        $userBefore = $user->fresh()->getAttributes();

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('pending_customer_registrations', ['registration_edit_token_hash'], 'unique'));
        $this->assertTrue(Schema::hasColumn('users', 'phone_verified_at'));
        $this->assertSame($pendingBefore, $pending->fresh()->getAttributes());
        $this->assertEquals($userBefore, $user->fresh()->only(array_keys($userBefore)));
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertDatabaseCount('users', 1);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'phone_verified_at'));
        $this->assertFalse(Schema::hasColumn('pending_customer_registrations', 'registration_edit_token_hash'));
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertDatabaseCount('users', 1);
        $migration->up();
        $this->assertTrue(Schema::hasIndex('pending_customer_registrations', ['registration_edit_token_hash'], 'unique'));
    }

    public function test_recovery_migration_is_repeatable_and_reversible_without_losing_accounts(): void
    {
        // Exercise historical DDL against its own pre-refactor schema.
        (require database_path('migrations/2026_11_07_000001_make_customer_registration_verify_first.php'))->down();
        $user = User::factory()->unverified()->create();
        $migration = require base_path('database/migrations/2026_10_22_000001_add_registration_recovery_grants.php');
        $migration->up();
        foreach (['users', 'pending_customer_registrations'] as $table) {
            $this->assertTrue(Schema::hasIndex($table, ['registration_recovery_token_hash'], 'unique'));
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), 'Index names must fit MySQL identifiers.');
            }
        }
        $migration->down();
        $this->assertDatabaseHas('users', ['user_id' => $user->getKey(), 'email' => $user->email]);
        foreach (['users', 'pending_customer_registrations'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'registration_recovery_token_hash'));
            $this->assertFalse(Schema::hasColumn($table, 'registration_recovery_expires_at'));
        }
        $this->assertFalse(Schema::hasIndex('pending_customer_registrations', 'pending_registration_created_idx'));
        $migration->up();
        $this->assertTrue(Schema::hasIndex('pending_customer_registrations', 'pending_registration_created_idx'));
    }

    public function test_recovery_migration_resumes_after_mysql_partial_schema_changes_without_losing_records(): void
    {
        // Exercise historical DDL against its own pre-refactor schema.
        (require database_path('migrations/2026_11_07_000001_make_customer_registration_verify_first.php'))->down();
        Notification::fake();
        PendingCustomerRegistration::forceCreate(['first_name'=>'Old','last_name'=>'Signup','email'=>'old@gmail.com','password_hash'=>'unused']);
        User::factory()->unverified()->create();
        $migration = require base_path('database/migrations/2026_10_22_000001_add_registration_recovery_grants.php');
        $migration->down();
        $usersBefore = DB::table('users')->get()->toArray();
        $pendingBefore = DB::table('pending_customer_registrations')->get()->toArray();

        // Match the committed DDL left by MySQL before the overlong index failed.
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_recovery_token_hash', 64)->nullable()->unique();
            $table->timestamp('registration_recovery_expires_at')->nullable();
        });
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->string('registration_recovery_token_hash', 64)->nullable();
        });

        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('users', 'users_registration_recovery_token_hash_unique', 'unique'));
        $this->assertTrue(Schema::hasIndex('pending_customer_registrations', 'pending_reg_recovery_token_uq', 'unique'));
        $this->assertTrue(Schema::hasIndex('pending_customer_registrations', 'pending_registration_created_idx'));
        foreach (['users', 'pending_customer_registrations'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'registration_recovery_token_hash'));
            $this->assertTrue(Schema::hasColumn($table, 'registration_recovery_expires_at'));
            $recoveryIndexes = array_filter(Schema::getIndexes($table), fn ($index) => $index['columns'] === ['registration_recovery_token_hash']);
            $this->assertCount(1, $recoveryIndexes);
        }

        $migration->down();
        $this->assertEquals($usersBefore, DB::table('users')->get()->toArray());
        $this->assertEquals($pendingBefore, DB::table('pending_customer_registrations')->get()->toArray());
    }

    public function test_pending_customers_are_not_listed_as_registered_and_cannot_reset_passwords(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertSame(0, User::registeredCustomer()->count());
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationTokenSentTo($pending);
        Notification::fake();
        $this->travel(61)->seconds();
        $this->postJson('/api/password/forgot', ['email' => $pending->email])->assertUnprocessable()
            ->assertJsonPath('message', 'No account found with this email.');
        $this->postJson('/api/password/code/verify', ['email' => $pending->email,
            'code' => $code])->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid or expired verification code.');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

}
