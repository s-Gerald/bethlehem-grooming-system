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
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
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

    public function test_original_browser_can_choose_review_without_replacing_or_consuming_the_link(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $before = $pending->getAttributes();
        $token = $this->verificationTokenSentTo($pending);
        Notification::fake();
        $this->postJson('/api/email/registration/review', ['email' => $pending->email])->assertAccepted()
            ->assertJsonMissingPath('details')->assertJsonMissingPath('registration_recovery_token');
        $this->assertSame($before, $pending->fresh()->getAttributes());
        Notification::assertNothingSent();
        $review = $this->postJson('/api/email/verify', ['token' => $token])->assertOk()
            ->assertJsonPath('requires_registration_review', true)->assertJsonMissingPath('token');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $payload = [...$this->registrationPayload(), 'first_name' => 'John', 'password' => 'owner-password',
            'password_confirmation' => 'owner-password', 'registration_recovery_token' => $review->json('registration_recovery_token')];
        $this->putJson('/api/register/pending', $payload)->assertOk();
        $this->assertDatabaseHas('users', ['first_name' => 'John']);
        $this->assertTrue(Hash::check('owner-password', User::sole()->password_hash));
        $this->putJson('/api/register/pending', $payload)->assertUnprocessable();
        $this->postJson('/api/email/verify', ['token' => $token])->assertUnprocessable();
    }

    public function test_link_confirmation_can_deliberately_request_review_in_the_original_browser(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo(PendingCustomerRegistration::sole()),
            'review' => true])->assertOk()->assertJsonPath('requires_registration_review', true)->assertJsonMissingPath('token');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_review_intent_is_generic_and_cannot_inspect_or_edit_another_registration(): void
    {
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload());
        $originalCookie = $response->getCookie('bethlehem_registration_browser', false)->getValue();
        $pending = PendingCustomerRegistration::sole();
        $before = $pending->getAttributes();
        $this->withUnencryptedCookie('bethlehem_registration_browser', str_repeat('x', 64))->withCredentials();
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
        $first = $this->postJson('/api/email/registration/review', ['email' => $pending->email])->assertAccepted()->json();
        $this->postJson('/api/email/registration/review', ['email' => 'unknown@gmail.com'])->assertAccepted()->assertExactJson($first);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/(?:from|into) ["`]*(?:users|pending_customer_registrations)/i', $sql))));
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->withUnencryptedCookie('bethlehem_registration_browser', $originalCookie)->withCredentials();
        $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending)])
            ->assertOk()->assertJsonMissingPath('requires_registration_review')->assertJsonStructure(['token']);
    }

    public function test_expired_review_intent_cannot_force_review_for_a_fresh_registration(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->postJson('/api/email/registration/review', ['email' => 'new.customer@gmail.com'])->assertAccepted();
        $old = PendingCustomerRegistration::sole();
        $this->travel(61)->minutes();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $fresh = PendingCustomerRegistration::sole();
        $this->assertNotSame($old->getKey(), $fresh->getKey());
        $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($fresh)])
            ->assertOk()->assertJsonMissingPath('requires_registration_review')->assertJsonStructure(['token']);
    }

    public function test_review_selected_before_the_registration_worker_runs_still_prevents_activation(): void
    {
        Notification::fake();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessPublicRegistration::class]);
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->postJson('/api/email/registration/review', ['email' => 'new.customer@gmail.com'])->assertAccepted();
        $job = null;
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessPublicRegistration::class, function ($queued) use (&$job) {
            $job = $queued;
            return true;
        });
        $this->travel(59)->minutes();
        $job->handle(app(\App\Services\CustomerRegistrationService::class));
        $this->travel(2)->minutes();
        $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo(PendingCustomerRegistration::sole())])
            ->assertOk()->assertJsonPath('requires_registration_review', true)->assertJsonMissingPath('token');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_public_registration_has_no_account_lookup_or_private_outcome_before_the_queued_work(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
        $first = $this->postJson('/api/register', $this->registrationPayload())->assertAccepted()
            ->assertJsonPath('registration_state', 'requested')
            ->assertJsonMissingPath('registration_token')->assertJsonMissingPath('email_delivery_token')->json();
        User::factory()->create(['email' => 'existing@gmail.com']);
        $queries = [];
        $this->postJson('/api/register', [...$this->registrationPayload(), 'email' => 'existing@gmail.com'])
            ->assertAccepted()->assertExactJson($first);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/(?:from|into) ["`]*(?:users|pending_customer_registrations)/i', $sql))));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessPublicRegistration::class, 2);
    }

    public function test_authorized_resume_preserves_details_link_and_longer_email_restrictions(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $credential = $this->authorizedEditCredential($response);
        $pending = PendingCustomerRegistration::sole();
        foreach (range(1, 2) as $attempt) {
            $this->travel(60)->seconds();
            $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $credential])->assertOk();
        }
        $before = $pending->fresh()->getAttributes();
        Notification::fake();
        $this->postJson('/api/register', [...$this->registrationPayload(), 'first_name' => 'Changed',
            'registration_token' => $credential])->assertAccepted()->assertJsonPath('registration_state', 'resumed')
            ->assertJsonPath('email_delivery_queued', false)->assertJsonPath('verification_link_available', true)
            ->assertJsonPath('verification_link_expires_at', $pending->fresh()->email_verification_expires_at->toIso8601String())
            ->assertJsonPath('resend_limited', true)->assertJsonPath('resend_after', 780);
        $this->assertSame($before, $pending->fresh()->getAttributes());
        Notification::assertNothingSent();
        $this->travel(1800)->seconds();
        $this->postJson('/api/register', [...$this->registrationPayload(), 'registration_token' => $credential])
            ->assertAccepted()->assertJsonPath('verification_link_available', false)->assertJsonPath('verification_link_expires_at', null);
    }

    public function test_public_work_is_encrypted_and_replay_preserves_the_current_registration_link(): void
    {
        $this->useDatabaseMailQueue();
        $this->postJson('/api/register', $this->registrationPayload())->assertAccepted()
            ->assertJsonMissingPath('email_delivery_token')->assertJsonMissingPath('registration_token');
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $command = json_decode(DB::table('jobs')->sole()->payload, true)['data']['command'];
        $this->assertStringNotContainsString('strong-password', $command);
        $this->assertStringNotContainsString('new.customer@gmail.com', $command);
        $job = unserialize(app('encrypter')->decrypt($command));
        $this->assertInstanceOf(\App\Jobs\ProcessPublicRegistration::class, $job);
        $this->assertArrayNotHasKey('password', $job->details);
        $this->assertTrue(Hash::check('strong-password', $job->details['password_hash']));
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $before = PendingCustomerRegistration::sole()->getAttributes();
        $job->handle(app(\App\Services\CustomerRegistrationService::class));
        $this->assertSame($before, PendingCustomerRegistration::sole()->getAttributes());
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_an_edit_credential_in_another_browser_cannot_resume_or_inspect_delivery(): void
    {
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload());
        $credential = $this->authorizedEditCredential($response);
        $before = PendingCustomerRegistration::sole()->getAttributes();
        $this->withUnencryptedCookie('bethlehem_registration_browser', str_repeat('x', 64))->withCredentials();
        $this->postJson('/api/register', [...$this->registrationPayload(), 'registration_token' => $credential])
            ->assertAccepted()->assertJsonPath('registration_state', 'requested')
            ->assertJsonMissingPath('registration_token')->assertJsonMissingPath('email_delivery_token');
        $this->postJson('/api/email/resend', ['email' => 'new.customer@gmail.com', 'registration_token' => $credential])
            ->assertAccepted()->assertJsonMissingPath('email_delivery_token');
        $this->assertSame($before, PendingCustomerRegistration::sole()->getAttributes());
    }

    public function test_public_resend_never_looks_up_an_account_and_hides_destination_history(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $first = $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])->assertAccepted()->json();
        User::factory()->create(['email' => 'existing@gmail.com']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
        $this->postJson('/api/email/resend', ['email' => 'existing@gmail.com'])->assertAccepted()->assertExactJson($first);
        $this->postJson('/api/email/resend', ['email' => 'existing@gmail.com'])->assertAccepted()->assertExactJson($first);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/(?:from|into) ["`]*(?:users|pending_customer_registrations)/i', $sql))));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessPublicRegistration::class, 2);
    }

    public function test_magic_link_registration_issues_a_hashed_fragment_token_and_private_browser_cookie(): void
    {
        Notification::fake();
        $this->travelTo(now()->startOfSecond());
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $cookie = $response->getCookie('bethlehem_registration_browser', false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $pending = PendingCustomerRegistration::sole();
        Notification::assertSentTo($pending, VerifyEmailNotification::class, function ($notification) use ($pending) {
            $url = $notification->toMail($pending)->actionUrl;
            parse_str(parse_url($url, PHP_URL_FRAGMENT), $fragment);
            $this->assertSame(64, strlen($fragment['token']));
            $this->assertSame(hash('sha256', $fragment['token']), $pending->email_verification_token);
            $this->assertTrue($pending->email_verification_expires_at->equalTo(now()->addMinutes(30)));
            $this->assertNull($pending->email_verification_code_hash);
            return true;
        });
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

    public function test_signup_and_email_proven_review_share_unicode_name_validation_and_casing(): void
    {
        Notification::fake();
        $payload = [...$this->registrationPayload(), 'first_name' => 'MARIA CLARA', 'last_name' => 'PEÑA'];
        $this->registerInBrowser($payload)->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $this->assertSame('Maria Clara', $pending->first_name);
        $review = $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending), 'review' => true])->assertOk();
        $this->putJson('/api/register/pending', [...$payload, 'registration_recovery_token' => $review->json('registration_recovery_token'),
            'first_name' => 'McDonald', 'last_name' => 'de la Cruz'])->assertOk();
        $this->assertDatabaseHas('users', ['first_name' => 'McDonald', 'last_name' => 'de la Cruz']);
        $this->registerInBrowser([...$this->registrationPayload(), 'email' => 'numeric@gmail.com', 'first_name' => 'Gerald２'])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->string('password_hash');
            $table->string('role')->default('customer');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('pending_customer_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('login_email_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('poll_token_hash', 64)->nullable()->unique();
            $table->boolean('remember_me')->default(true);
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_30_000001_isolate_login_email_challenges.php'))->up();
        (require base_path('database/migrations/2026_10_30_000002_create_account_security_incidents.php'))->up();

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tokenable_type', 'tokenable_id']);
        });
        (require base_path('database/migrations/2026_10_18_000001_add_token_session_state.php'))->up();
        (require base_path('database/migrations/2026_10_25_000001_add_session_replacement.php'))->up();
        (require base_path('database/migrations/2026_10_29_000003_create_remembered_devices.php'))->up();
        (require base_path('database/migrations/2026_10_05_000001_add_registration_verification_codes.php'))->up();
        (require base_path('database/migrations/2026_10_22_000001_add_registration_recovery_grants.php'))->up();
        (require base_path('database/migrations/2026_10_28_000002_add_registration_browser_binding.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('account_security_incidents');
        Schema::dropIfExists('remembered_device_credentials');
        Schema::dropIfExists('remembered_devices');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('login_email_challenges');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }


    public function test_verification_creates_the_customer_account_and_logs_it_in(): void
    {
        Notification::fake();

        $registration = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pendingRegistration = PendingCustomerRegistration::firstOrFail();
        $plainToken = $this->verificationTokenSentTo($pendingRegistration);

        $this->postJson('/api/email/verify', ['registration_token' => $this->authorizedEditCredential($registration), 'email' => $pendingRegistration->email, 'token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'new.customer@gmail.com')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $user = User::where('email', 'new.customer@gmail.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('strong-password', $user->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }


    public function test_registration_remains_recoverable_when_notification_queueing_fails(): void
    {
        $this->useDatabaseMailQueue();
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        });
        $this->registerInBrowser($this->registrationPayload())->assertAccepted()
            ->assertJsonPath('registration_state', 'requested')->assertJsonMissingPath('email_delivery_queued');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('jobs', 1);
        Notification::fake();
        $this->app->instance(Dispatcher::class, Notification::getFacadeRoot());
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $pending = PendingCustomerRegistration::sole();
        $this->assertNotNull($pending->email_verification_token);
        Notification::assertSentTo($pending, VerifyEmailNotification::class);
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

    public function test_pending_registration_gets_verification_message_instead_of_signing_in(): void
    {
        PendingCustomerRegistration::create([
            'first_name' => 'Pending',
            'last_name' => 'Customer',
            'username' => 'pendingcustomer',
            'phone' => '09120000000',
            'email' => 'pending@gmail.com',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => 'pending@gmail.com',
            'password' => 'strong-password',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified')
            ->assertJsonPath('email_not_verified', true);

        $this->assertDatabaseCount('users', 0);
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

    public function test_resend_is_generic_and_issues_a_link_for_existing_unverified_users(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/email/resend', ['email' => strtoupper($user->email)])
            ->assertAccepted()
            ->assertJsonPath('success', true);

        $plainToken = $this->verificationTokenSentTo($user);
        $this->assertSame(hash('sha256', $plainToken), $user->fresh()->email_verification_token);

        Notification::fake();
        $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
            ->assertAccepted()
            ->assertJsonPath(
                'message',
                'Request received. Email delivery is not confirmed.',
            );
        Notification::assertNothingSent();
    }

    public function test_resend_issues_a_pending_registration_link(): void
    {
        Notification::fake();
        $pendingRegistration = PendingCustomerRegistration::create([
            'first_name' => 'Pending',
            'last_name' => 'Customer',
            'username' => 'pendingresend',
            'phone' => '09120000001',
            'email' => 'pending-resend@gmail.com',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/email/resend', [
            'email' => strtoupper($pendingRegistration->email),
        ])->assertAccepted();

        $plainToken = $this->verificationTokenSentTo($pendingRegistration);
        $this->assertSame(hash('sha256', $plainToken), $pendingRegistration->fresh()->email_verification_token);
        $this->assertNotNull($pendingRegistration->fresh()->email_verification_token);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_failed_resend_queueing_does_not_invalidate_the_previous_link(): void
    {
        $this->useDatabaseMailQueue();
        $oldTokenHash = hash('sha256', str_repeat('d', 64));
        $oldExpiry = now()->addHour()->startOfSecond();
        $user = User::factory()->unverified()->create([
            'email_verification_token' => $oldTokenHash,
            'email_verification_expires_at' => $oldExpiry,
        ]);

        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('Queue unavailable'));
        });

        $this->postJson('/api/email/resend', ['email' => $user->email])
            ->assertAccepted()
            ->assertJsonMissingPath('email_delivery_token');

        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $user->refresh();
        $this->assertSame($oldTokenHash, $user->email_verification_token);
        $this->assertTrue($oldExpiry->equalTo($user->email_verification_expires_at));
    }

    public function test_resend_endpoint_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
                ->assertAccepted();
            $this->travel(61)->seconds();
        }

        $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
            ->assertAccepted();
        $this->assertGreaterThan(0, app(\App\Services\AuthenticationEmailPolicy::class)->status('missing@gmail.com')['resend_after']);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('optionalPhones')]
    public function test_optional_phone_variants_complete_only_after_email_verification(array $phoneInput, ?string $expectedPhone): void
    {
        Notification::fake();
        User::factory()->create(['phone' => null]);
        $payload = $this->registrationPayload();
        unset($payload['phone']);
        $payload = [...$payload, ...$phoneInput];
        $payload['email'] = 'new.customer@gmail.com';
        $registration = $this->registerInBrowser($payload)->assertAccepted()
            ->assertJsonPath('requires_verification', true)->assertJsonMissingPath('token');
        $pending = PendingCustomerRegistration::where('email', $payload['email'])->firstOrFail();
        $this->assertSame($expectedPhone, $pending->phone);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->postJson('/api/email/verify', ['email' => $pending->email])->assertUnprocessable();
        $verified = $this->postJson('/api/email/verify', [
            'registration_token' => $this->authorizedEditCredential($registration), 'email' => $pending->email, 'token' => $this->verificationTokenSentTo($pending),
        ])->assertOk()->assertJsonStructure(['token']);
        $user = User::where('email', $payload['email'])->firstOrFail();
        $this->assertSame($expectedPhone, $user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($user->email_verified_at);
        $this->withAccountSession($verified)->getJson('/api/me')
            ->assertOk()->assertJsonPath('user.phone', $expectedPhone)
            ->assertJsonPath('user.phone_verification_status', $expectedPhone ? 'unverified' : 'not_added');
        $this->assertDatabaseCount('users', 2);
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

    public static function optionalPhones(): array
    {
        return [
            'null' => [['phone' => null], null],
            'empty' => [['phone' => ''], null],
            'omitted' => [[], null],
            'whitespace' => [['phone' => '   '], null],
            'valid' => [['phone' => '09170000001'], '09170000001'],
        ];
    }

    public function test_nullable_phone_migration_preserves_records_and_unique_indexes(): void
    {
        foreach (['users', 'pending_customer_registrations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('phone', 20)->nullable(false)->change());
        }
        $user = User::factory()->create(['phone' => '09170000001']);
        $pending = PendingCustomerRegistration::create([
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

    public function test_multiple_phone_less_registrations_can_remain_pending(): void
    {
        Notification::fake();
        foreach (['first', 'second'] as $name) {
            $this->registerInBrowser([
                ...$this->registrationPayload(), 'email' => "$name@gmail.com", 'phone' => null, 'username' => null,
            ])->assertAccepted();
        }
        $this->assertDatabaseCount('pending_customer_registrations', 2);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignupPhones')]
    public function test_invalid_signup_phones_are_rejected(mixed $phone): void
    {
        $this->registerInBrowser([...$this->registrationPayload(), 'phone' => $phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('pending_customer_registrations', 0);
    }

    public static function invalidSignupPhones(): array
    {
        return [['09123'], ['56775675767'], ['12345678901'], ['08123456789'], ['09031234567'], ['07123456789'], ["09123\n456789"], ['091234567890'], ['not-a-phone'], [9123456789], [[]], [['09123456789']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validSignupPhones')]
    public function test_mobile_registration_accepts_all_networks_without_phone_otp(string $phone): void
    {
        Notification::fake();
        \Illuminate\Support\Facades\Http::fake();
        $response = $this->registerInBrowser([...$this->registrationPayload(), 'phone' => $phone])->assertAccepted();
        $pending = PendingCustomerRegistration::firstOrFail();
        $this->assertSame($phone, $pending->phone);
        $this->postJson('/api/email/verify', ['registration_token' => $this->authorizedEditCredential($response),
            'email' => $pending->email, 'token' => $this->verificationTokenSentTo($pending)])->assertOk();
        $this->assertDatabaseHas('users', ['phone' => $phone, 'phone_verified_at' => null]);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public static function validSignupPhones(): array
    {
        return array_map(fn ($prefix) => [$prefix.'1234567'], ['0917', '0895', '0896', '0897', '0898', '0991', '0999', '0907', '0922', '0813', '0817', '0927', '0956', '0966', '0977']);
    }

    public function test_email_proven_review_can_remove_or_replace_the_optional_phone(): void
    {
        Notification::fake();
        foreach (['', '08958889999'] as $index => $phone) {
            $payload = [...$this->registrationPayload(), 'email' => "phone{$index}@gmail.com", 'username' => null];
            $this->registerInBrowser($payload)->assertAccepted();
            $pending = PendingCustomerRegistration::sole();
            $review = $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending), 'review' => true])->assertOk();
            $completion = [...$payload, 'phone' => $phone, 'registration_recovery_token' => $review->json('registration_recovery_token')];
            $this->putJson('/api/register/pending', $completion)->assertOk();
            $this->putJson('/api/register/pending', $completion)->assertUnprocessable();
            $this->assertDatabaseHas('users', ['email' => $payload['email'], 'phone' => $phone ?: null, 'phone_verified_at' => null]);
            $this->travel(61)->seconds();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ditoLoginIdentifiers')]
    public function test_registered_dito_number_can_start_existing_email_login_confirmation(string $identifier): void
    {
        Notification::fake();
        User::factory()->create(['phone' => '08951234567', 'password_hash' => Hash::make('strong-password')]);
        $this->postJson('/api/sign-in', ['identifier' => $identifier, 'password' => 'strong-password'])
            ->assertAccepted()->assertJsonPath('code', 'login_confirmation_required');
        $this->assertDatabaseCount('login_email_challenges', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function ditoLoginIdentifiers(): array
    {
        return [['08951234567'], ['+638951234567']];
    }


    public function test_resend_cooldown_is_enforced_across_different_ips(): void
    {
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::firstOrFail();
        $hash = $pending->email_verification_code_hash;
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52']);
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $this->authorizedEditCredential($response)])
            ->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Please wait before requesting another email.');
        $this->assertSame($hash, $pending->fresh()->email_verification_code_hash);
    }

    public function test_duplicate_contacts_and_username_are_still_protected(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted()
            ->assertJsonMissingPath('requires_registration_recovery')->assertJsonMissingPath('registration_token');
        $this->registerInBrowser([...$this->registrationPayload(), 'email' => 'different@gmail.com'])
            ->assertAccepted()->assertJsonMissingPath('errors');
        $this->assertDatabaseMissing('pending_customer_registrations', ['email' => 'different@gmail.com']);
        PendingCustomerRegistration::query()->delete();
        User::factory()->create(['email' => 'new.customer@gmail.com', 'phone' => '09123456789', 'username' => 'newcustomer']);
        $this->registerInBrowser($this->registrationPayload())->assertAccepted()
            ->assertJsonMissingPath('requires_registration_recovery')->assertJsonMissingPath('registration_token');
        $this->registerInBrowser([...$this->registrationPayload(), 'email' => 'different@gmail.com'])
            ->assertAccepted()->assertJsonMissingPath('errors');
        $this->assertDatabaseMissing('pending_customer_registrations', ['email' => 'different@gmail.com']);
    }

    public function test_verification_rechecks_all_unique_registration_details(): void
    {
        Notification::fake();
        foreach (['email', 'phone', 'username'] as $field) {
            $registration = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
            $pending = PendingCustomerRegistration::firstOrFail();
            $code = $this->verificationTokenSentTo($pending);
            $conflict = User::factory()->create([$field => $pending->$field]);
            $this->postJson('/api/email/verify', ['registration_token' => $this->authorizedEditCredential($registration), 'email' => $pending->email, 'token' => $code])
                ->assertUnprocessable()->assertJsonMissingPath('token');
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('pending_customer_registrations', 1);
            PendingCustomerRegistration::query()->delete();
            $conflict->delete();
            Notification::fake();
            $this->travel(901)->seconds();
        }
    }

    public function test_failed_first_delivery_can_be_retried_without_registering_again(): void
    {
        $this->useDatabaseMailQueue();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertAccepted();
        $this->withUnencryptedCookie('bethlehem_registration_browser', $response->getCookie('bethlehem_registration_browser', false)->getValue())->withCredentials();
        DB::statement("CREATE TRIGGER reject_auth_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('jobs', 1);
        DB::statement('DROP TRIGGER reject_auth_job');
        $this->assertNotNull(DB::table('jobs')->value('reserved_at'), 'A database insert outage also prevents release; the lease must recover it.');
        $this->travel((int) config('queue.connections.database.retry_after', 90) + 1)->seconds();
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        Notification::fake();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $pending = PendingCustomerRegistration::sole();
        $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending)])->assertOk();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_failed_resend_preserves_the_previous_link(): void
    {
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::firstOrFail();
        $code = $this->verificationTokenSentTo($pending);
        $pending->update(['email_verification_attempts' => 2]);
        $hash = $pending->email_verification_token;
        $this->travel(61)->seconds();
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        });
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $this->authorizedEditCredential($response)])->assertStatus(503);
        $this->assertSame($hash, $pending->fresh()->email_verification_token);
        $this->assertSame(2, $pending->fresh()->email_verification_attempts);
        $this->postJson('/api/email/verify', ['registration_token' => $this->authorizedEditCredential($response), 'email' => $pending->email, 'token' => $code])->assertOk();
    }

    public function test_browser_edit_credential_cannot_replace_details_before_email_proof(): void
    {
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $credential = $this->authorizedEditCredential($response);
        $pending = PendingCustomerRegistration::sole();
        $before = $pending->getAttributes();
        $token = $this->verificationTokenSentTo($pending);
        $this->putJson('/api/register/pending', [...$this->registrationPayload(), 'first_name' => 'Attacker',
            'password' => 'changed-password', 'password_confirmation' => 'changed-password', 'registration_token' => $credential])
            ->assertUnprocessable()->assertJsonValidationErrors('registration_recovery_token');
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->postJson('/api/email/verify', ['token' => $token])->assertOk()->assertJsonPath('user.first_name', 'New');
        $this->assertTrue(Hash::check('strong-password', User::sole()->password_hash));
    }

    public function test_no_sms_endpoint_or_phone_payload_can_complete_registration(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->postJson('/api/email/verify', ['phone' => '09123456789', 'token' => '123456', 'channel' => 'sms'])->assertUnprocessable();
        $this->postJson('/api/sms/verify', ['phone' => '09123456789', 'token' => '123456'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
    }


    public function test_log_mailer_cannot_record_signup_tokens(): void
    {
        $this->useDatabaseMailQueue();
        Notification::fake();
        foreach (['log', 'failover'] as $mailer) {
            config()->set('mail.default', $mailer);
            $this->registerInBrowser($this->registrationPayload())->assertAccepted()
                ->assertJsonMissingPath('email_delivery_queued')->assertJsonMissingPath('email_delivery_token');
            Notification::assertNothingSent();
            $this->assertDatabaseCount('pending_customer_registrations', 0);
            $this->assertDatabaseCount('users', 0);
            DB::table('jobs')->delete();
            $this->travel(61)->seconds();
        }
    }

    public function test_registration_migration_resumes_partial_schema_and_preserves_data(): void
    {
        $migration = require base_path('database/migrations/2026_10_05_000001_add_registration_verification_codes.php');
        // Match MySQL's state after the original index-name failure: the new
        // verification fields exist, but the unique index and phone field do not.
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->dropUnique('pending_registration_edit_token_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
        $pending = PendingCustomerRegistration::create([
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

    public function test_database_queue_delivers_registration_mail_and_reports_transport_acceptance(): void
    {
        $timings = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class,
            function ($event) use (&$timings): void {
                if ($event->message === 'Authentication mail delivery') $timings[] = $event->context;
            });
        $this->useDatabaseMailQueue();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $receipt = $this->privateDeliveryReceipt();
        $this->assertIsString($receipt);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'queued');
        $payload = DB::table('jobs')->value('payload');
        $command = json_decode($payload, true)['data']['command'];
        $this->assertStringNotContainsString('VerifyEmailNotification', $command);

        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        preg_match('/#token=([A-Za-z0-9]{64})/', $email->getTextBody(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString($matches[1], $payload);
        $this->assertSame(['queued', 'sending', 'sent'], array_column($timings, 'status'));
        foreach ($timings as $timing) {
            $this->assertSame([], array_diff(array_keys($timing), ['delivery_id', 'status', 'elapsed_ms', 'request_ms', 'transport_ms']));
            $this->assertGreaterThanOrEqual(0, $timing['elapsed_ms']);
            $this->assertStringNotContainsString($matches[1], json_encode($timing));
            $this->assertStringNotContainsString($receipt, json_encode($timing));
            $this->assertStringNotContainsString('new.customer@gmail.com', json_encode($timing));
        }
        $this->assertGreaterThanOrEqual(0, $timings[2]['transport_ms']);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->postJson('/api/email/verify', ['registration_token' => $this->authorizedEditCredential($response), 'email' => 'new.customer@gmail.com', 'token' => $matches[1]])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_database_queue_delivers_otp_for_every_login_role(): void
    {
        $this->useDatabaseMailQueue();
        foreach (['customer', 'staff', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'password_hash' => Hash::make('strong-password')]);
            $response = $this->postJson('/api/sign-in', [
                'identifier' => $user->email, 'password' => 'strong-password',
            ])->assertAccepted()->assertJsonMissingPath('token');
            $this->assertSame(0, $user->tokens()->count());
            $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
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

    public function test_transport_failure_is_reported_without_exposing_secrets_and_can_retry(): void
    {
        $this->useDatabaseMailQueue();
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
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'retrying')->assertDontSee('Private SMTP');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('jobs', 1);
        config()->set('mail.default', 'array');
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'sent');
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

    public function test_public_resend_delivery_status_does_not_enumerate_addresses(): void
    {
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->travel(61)->seconds();
        $this->postJson('/api/email/resend', ['email' => 'new.customer@gmail.com'])
            ->assertAccepted()->assertJsonMissingPath('email_delivery_token');
        $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
            ->assertAccepted()->assertJsonMissingPath('email_delivery_token');
        $this->travel(61)->seconds();
        $resend = $this->postJson('/api/email/resend', [
            'email' => 'new.customer@gmail.com', 'registration_token' => $this->authorizedEditCredential($response),
        ])->assertOk();
        $this->assertIsString($resend->json('email_delivery_token'));
        $this->postJson('/api/email/delivery-status', ['delivery_token' => str_repeat('x', 64)])
            ->assertOk()->assertJsonPath('status', 'unavailable')
            ->assertJsonMissingPath('email')->assertJsonMissingPath('code');
    }

    public function test_public_registration_stays_asynchronous_in_immediate_mode_without_duplicate_mail(): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', 'immediate');
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $receipt = $this->privateDeliveryReceipt();
        $this->app->terminate();
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages(), 'Public processing must not retain post-response mail callbacks in the worker.');
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $this->privateDeliveryReceipt()])
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_replaced_and_expired_registration_jobs_do_not_send(): void
    {
        $this->useDatabaseMailQueue();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $receipt = $this->privateDeliveryReceipt();
        $this->travel(61)->seconds();
        $this->postJson('/api/email/resend', ['email' => 'new.customer@gmail.com', 'registration_token' => $this->authorizedEditCredential($response)])->assertOk();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'superseded');
        // A queued issuance must not send after its deadline.
        PendingCustomerRegistration::query()->update(['email_verification_expires_at' => now()->addMinutes(15)]);
        $this->travel(16)->minutes();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_replaced_login_job_does_not_send_but_current_code_authenticates(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $this->travel(61)->seconds();
        $this->postJson('/api/email/login/resend', ['poll_token' => $response->json('login_poll_token')])->assertOk();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
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
                ->assertOk()->assertJsonPath('status', $attempt === 1 ? 'queued' : 'retrying')->assertDontSee('Private SMTP');
            DB::table('jobs')->update(['available_at' => now()->timestamp]);
            $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
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
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 1);
        $lock->release();
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_removed_pending_registration_is_skipped_without_a_failed_job(): void
    {
        $this->useDatabaseMailQueue();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        PendingCustomerRegistration::query()->delete();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_queue_insert_failure_rolls_back_the_registration_issuance(): void
    {
        $this->useDatabaseMailQueue();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $before = $pending->getAttributes();
        $this->travel(61)->seconds();
        DB::statement("CREATE TRIGGER reject_auth_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        try {
            DB::transaction(fn () => app(\App\Services\RegistrationMagicLinkService::class)->send($pending));
            $this->fail('Queue submission should fail.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($before, $pending->fresh()->getAttributes());
            $this->assertDatabaseCount('jobs', 1);
        }
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
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
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
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
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

    public function test_registration_link_has_exactly_thirty_minutes_of_server_validity(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationTokenSentTo($pending);
        $this->assertTrue($pending->email_verification_expires_at->equalTo($pending->email_verification_last_sent_at->copy()->addMinutes(30)));
        $this->travel(30)->minutes();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'token' => $code,
            'registration_token' => $this->authorizedEditCredential($response)])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_signup_responses_do_not_disclose_recovery_eligibility_or_overwrite_details(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $before = $pending->getAttributes();
        $payload = [...$this->registrationPayload(), 'first_name' => 'Attacker',
            'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'];
        $publicResponse = $this->registerInBrowser($payload)
            ->assertAccepted()->assertJsonPath('requires_verification', true)
            ->assertJsonMissingPath('requires_registration_recovery')
            ->assertJsonMissingPath('registration_token')->assertJsonMissingPath('details')->assertJsonMissingPath('token')->json();
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 0);
        PendingCustomerRegistration::query()->delete();

        foreach ([['email_verified_at' => null], [], ['is_active' => false], ['is_archived' => true], ['role' => 'staff']] as $attributes) {
            $this->travel(61)->seconds();
            $user = User::factory()->create(['email' => 'new.customer@gmail.com', ...$attributes]);
            $before = $user->fresh()->getAttributes();
            Notification::fake();
            $this->registerInBrowser($payload)->assertAccepted()->assertExactJson($publicResponse);
            Notification::assertNothingSent();
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertDatabaseCount('pending_customer_registrations', 0);
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $user->delete();
        }
    }

    public function test_public_recovery_is_identical_for_unknown_expired_pending_and_existing_accounts(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $pending->forceFill(['created_at' => now()->subHour()])->save();
        $publicResponse = $this->postJson('/api/email/resend', ['email' => 'unknown@gmail.com'])->assertAccepted()->json();
        foreach ([$pending->email, 'unknown@gmail.com'] as $email) {
            $this->travel(901)->seconds();
            Notification::fake();
            $this->postJson('/api/email/resend', ['email' => $email])->assertAccepted()->assertJson(\Illuminate\Support\Arr::except($publicResponse, ['next_eligible_at']));
            Notification::assertNothingSent();
        }
        foreach ([['email_verified_at' => null], [], ['is_active' => false], ['is_archived' => true]] as $index => $attributes) {
            $this->travel(901)->seconds();
            $user = User::factory()->create(['email' => "recovery{$index}@gmail.com", ...$attributes]);
            $this->postJson('/api/email/resend', ['email' => $user->email])->assertAccepted()->assertJson(\Illuminate\Support\Arr::except($publicResponse, ['next_eligible_at']));
        }
    }

    public function test_email_proof_is_required_before_recovery_guidance_and_saved_details_are_returned(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $this->registerInBrowser([...$this->registrationPayload(), 'first_name' => 'Attacker',
            'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'])
            ->assertAccepted()->assertJsonPath('requires_verification', true)
            ->assertJsonMissingPath('requires_registration_recovery')
            ->assertJsonMissingPath('registration_token')->assertJsonMissingPath('details')->assertJsonMissingPath('token');
        $this->withUnencryptedCookie('bethlehem_registration_browser', '')->withCredentials();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'token' => 'invalid'])
            ->assertUnprocessable()->assertJsonMissingPath('requires_registration_recovery')->assertJsonMissingPath('details');
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'token' => $this->verificationTokenSentTo($pending)])
            ->assertOk()->assertJsonPath('requires_registration_review', true)->assertJsonPath('details.first_name', 'New')
            ->assertJsonStructure(['registration_recovery_token'])->assertJsonMissingPath('token');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_lost_edit_credential_requires_ownership_then_explicit_reviewed_completion(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationTokenSentTo($pending);
        $this->withUnencryptedCookie('bethlehem_registration_browser', '')->withCredentials();
        $review = $this->postJson('/api/email/verify', ['email' => $pending->email, 'token' => $code])
            ->assertOk()->assertJsonPath('requires_registration_review', true)
            ->assertJsonMissingPath('token')->assertJsonMissingPath('details.password_hash');
        $grant = $review->json('registration_recovery_token');
        $this->assertSame(64, strlen($grant));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'token' => $code])->assertUnprocessable();
        $payload = [...$this->registrationPayload(), 'registration_recovery_token' => $grant];
        $this->putJson('/api/register/pending', [...$payload, 'email' => 'other@gmail.com'])->assertUnprocessable();
        $this->putJson('/api/register/pending', [...$payload, 'registration_recovery_token' => str_repeat('x', 64)])->assertUnprocessable();
        $this->putJson('/api/register/pending', $payload)->assertOk()->assertJsonStructure(['token', 'user']);
        $this->putJson('/api/register/pending', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_recovery_grant_expires_without_completing_or_deleting_registration(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $this->withUnencryptedCookie('bethlehem_registration_browser', '')->withCredentials();
        $review = $this->postJson('/api/email/verify', ['email' => $pending->email,
            'token' => $this->verificationTokenSentTo($pending)])->assertOk();
        $this->travel(10)->minutes();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(),
            'registration_recovery_token' => $review->json('registration_recovery_token')])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
    }

    public function test_retention_is_based_on_creation_and_expired_identifiers_are_released(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $pending->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()])->save();
        $oldCode = $this->verificationTokenSentTo($pending);
        $this->travel(61)->seconds();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'token' => $oldCode])->assertUnprocessable();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertNotSame($pending->getKey(), PendingCustomerRegistration::query()->sole()->getKey());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_recovery_prefers_existing_user_and_preserves_its_identity(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $legacy = User::factory()->unverified()->create(['email' => 'new.customer@gmail.com']);
        $id = $legacy->getKey();
        foreach (['pets', 'bookings'] as $table) {
            Schema::create($table, function (Blueprint $schema) {
                $schema->id();
                $schema->unsignedInteger('user_id');
                $schema->foreign('user_id')->references('user_id')->on('users');
            });
            DB::table($table)->insert(['user_id' => $id]);
        }
        $this->travel(61)->seconds();
        Notification::fake();
        $this->postJson('/api/email/resend', ['email' => $legacy->email])->assertAccepted();
        $code = $this->verificationTokenSentTo($legacy);
        $review = $this->postJson('/api/email/verify', ['email' => $legacy->email, 'token' => $code])
            ->assertOk()->assertJsonPath('requires_registration_review', true)->assertJsonMissingPath('token');
        $this->assertNull($legacy->fresh()->email_verified_at);
        $this->putJson('/api/register/pending', [...$this->registrationPayload(),
            'registration_recovery_token' => $review->json('registration_recovery_token')])
            ->assertOk()->assertJsonPath('user.user_id', $id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertNotNull($legacy->fresh()->email_verified_at);
        $this->assertDatabaseHas('pets', ['user_id' => $id]);
        $this->assertDatabaseHas('bookings', ['user_id' => $id]);
    }

    public function test_registration_link_works_just_before_thirty_minutes(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $this->travel(1799)->seconds();
        $this->postJson('/api/email/verify', ['email' => $pending->email,
            'registration_token' => $this->authorizedEditCredential($response),
            'token' => $this->verificationTokenSentTo($pending)])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_new_legacy_registration_links_expire_in_thirty_minutes(): void
    {
        Notification::fake();
        $legacy = User::factory()->unverified()->create();
        $this->postJson('/api/email/resend', ['email' => $legacy->email])->assertAccepted();
        $legacy->refresh();
        $this->assertSame(1800, $legacy->email_verification_expires_at->timestamp - $legacy->email_verification_last_sent_at->timestamp);
        $code = $this->verificationTokenSentTo($legacy);
        $this->travel(1801)->seconds();
        $this->postJson('/api/email/verify', ['email' => $legacy->email, 'token' => $code])->assertUnprocessable();
        $this->assertNull($legacy->fresh()->email_verified_at);
    }

    public function test_resend_does_not_extend_retention_and_expired_rows_are_not_mailed(): void
    {
        $this->useDatabaseMailQueue();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $pending->forceFill(['created_at' => now()->subHour()->addSeconds(70)])->save();
        $created = $pending->created_at->timestamp;
        $this->travel(61)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $this->assertSame($created, $pending->fresh()->created_at->timestamp);
        $this->travel(15)->seconds();
        // The worker shares this process with PHPUnit, whose full-suite budget is 512 MB.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 512])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->travel(61)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted()->assertJsonMissingPath('email_delivery_token');
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(1, PendingCustomerRegistration::pruneExpired());
    }

    public function test_verified_user_collision_never_uses_pending_credentials_or_deletes_the_user(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationTokenSentTo($pending);
        $verified = User::factory()->create(['email' => $pending->email]);
        $before = $verified->fresh()->getAttributes();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'token' => $code])->assertUnprocessable();
        Notification::fake();
        $this->travel(61)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        Notification::assertNothingSent();
        $this->assertSame($before, $verified->fresh()->getAttributes());
        $this->assertDatabaseCount('personal_access_tokens', 0);
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
        $this->postJson('/api/password/forgot', ['email' => $pending->email])->assertAccepted()
            ->assertJsonPath('message', 'If eligible, a new code was requested. Check your email.');
        $this->postJson('/api/password/code/verify', ['email' => $pending->email,
            'code' => $code])->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid or expired verification code.');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_recovery_completion_rolls_back_if_session_creation_fails(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $this->withUnencryptedCookie('bethlehem_registration_browser', '')->withCredentials();
        $review = $this->postJson('/api/email/verify', ['email' => $pending->email,
            'token' => $this->verificationTokenSentTo($pending)])->assertOk();
        DB::statement("CREATE TRIGGER reject_customer_session BEFORE INSERT ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'session unavailable'); END");
        $payload = [...$this->registrationPayload(), 'registration_recovery_token' => $review->json('registration_recovery_token')];
        $this->putJson('/api/register/pending', $payload)->assertServerError();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        DB::statement('DROP TRIGGER reject_customer_session');
        $this->putJson('/api/register/pending', $payload)->assertOk();
        $this->assertDatabaseCount('pending_customer_registrations', 0);
    }

    public function test_recovery_migration_is_repeatable_and_reversible_without_losing_accounts(): void
    {
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
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
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

    public static function simultaneousVerificationFlows(): array
    {
        return [['registration'], ['login']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('simultaneousVerificationFlows')]
    public function test_overlapping_verification_requests_consume_only_one_issuance_on_sqlite(string $flow): void
    {
        Notification::fake();
        if ($flow === 'login') {
            $user = User::factory()->create();
            $token = str_repeat('a', 64);
            LoginEmailChallenge::create(['user_id' => $user->getKey(), 'poll_token_hash' => hash('sha256', $token),
                'token_hash' => hash_hmac('sha256', '123456', $token), 'expires_at' => now()->addMinutes(10)]);
            $payload = json_encode(['poll_token' => $token, 'code' => '123456', '_verification_endpoint' => '/api/email/login/confirm'], JSON_THROW_ON_ERROR);
        } else {
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::query()->sole();
        $payload = json_encode(['email' => $pending->email, 'token' => $this->verificationTokenSentTo($pending),
            'registration_token' => $this->authorizedEditCredential($response),
            'registration_browser' => $response->getCookie('bethlehem_registration_browser', false)->getValue()], JSON_THROW_ON_ERROR);
        }
        $directory = sys_get_temp_dir().'/bethlehem-signup-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/registration.sqlite';
        $owner = bin2hex(random_bytes(16));
        file_put_contents($directory.'/owner', $owner);
        $first = $second = $connection = $probe = $contender = null;
        try {
            // Match the existing SQLite concurrency tests: prove runtime support,
            // rather than treating a PHP version or configuration flag as evidence.
            $probePath = $directory.'/probe.sqlite';
            $probe = new \Illuminate\Database\SQLiteConnection(new \PDO('sqlite:'.$probePath), $probePath, '', ['transaction_mode' => 'IMMEDIATE']);
            $contender = new \PDO('sqlite:'.$probePath);
            $contender->exec('PRAGMA busy_timeout=0');
            $probe->beginTransaction();
            $tracked = $probe->getPdo()->inTransaction();
            $writerLocked = false;
            try {
                $contender->exec('BEGIN IMMEDIATE');
                $contender->exec('ROLLBACK');
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 5) throw $error;
                $writerLocked = true;
            }
            $probe->rollBack();
            $probe->disconnect();
            $probe = $contender = null;
            if (! $tracked || ! $writerLocked) {
                $this->markTestSkipped('This Laravel/PDO SQLite runtime cannot enforce and track IMMEDIATE transactions. Use the documented compatible PHP runtime.');
            }
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                [$script, $database, $role, $owner, $payload] = $argv;
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-signup-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'registration.sqlite' || !is_file($database)
                    || !hash_equals(file_get_contents($directory.'/owner'), $owner)) {
                    throw new RuntimeException('Worker requires its owned disposable registration database.');
                }
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
                Illuminate\Support\Facades\DB::purge('sqlite');
                $connection = Illuminate\Support\Facades\DB::connection();
                if (realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) {
                    throw new RuntimeException('Worker opened an unexpected database.');
                }
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Verification barrier timed out.');
                };
                $busy = false;
                $connection->beforeStartingTransaction(function () use ($role, $directory, $database, $wait, &$busy) {
                    if ($role !== 'second') return;
                    $wait($directory.'/first-lock');
                    $probe = new PDO('sqlite:'.$database);
                    $probe->exec('PRAGMA busy_timeout=0');
                    try {
                        $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK');
                        throw new RuntimeException('The first verification must hold the writer lock.');
                    } catch (PDOException $error) {
                        if (($error->errorInfo[1] ?? null) !== 5) throw $error;
                        $busy = true;
                    }
                    touch($directory.'/second-attempt');
                });
                $app['events']->listen(Illuminate\Database\Events\TransactionBeginning::class,
                    function () use ($role, $directory, $wait) {
                        if ($role !== 'first') return;
                        touch($directory.'/first-lock');
                        $wait($directory.'/second-attempt');
                        usleep(200000);
                    });
                $parameters = json_decode($payload, true);
                $request = Illuminate\Http\Request::create($parameters['_verification_endpoint'] ?? '/api/email/verify', 'POST', $parameters,
                    ['bethlehem_registration_browser' => $parameters['registration_browser'] ?? ''],
                    server: ['REMOTE_ADDR' => $role === 'first' ? '192.0.2.1' : '192.0.2.2',
                        'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
                echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'busy' => $busy]);
                PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            $first = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'first', $owner, $payload], base_path(), $environment);
            $second = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'second', $owner, $payload], base_path(), $environment);
            $first->setTimeout(15); $second->setTimeout(15);
            $first->start(); $second->start();
            $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($one['pid'], $two['pid']);
            $this->assertTrue($two['busy'], 'The requests must overlap while the first owns the database lock.');
            $this->assertSame(200, $one['status']);
            $this->assertSame(422, $two['status']);
            $connection = new \PDO('sqlite:'.$database);
            $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM pending_customer_registrations')->fetchColumn());
            $this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM login_email_challenges')->fetchColumn());
            $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn());
        } finally {
            $first?->stop(); $second?->stop();
            if ($probe?->transactionLevel()) $probe->rollBack();
            $probe?->disconnect();
            $connection = $probe = $contender = null;
            foreach (['registration.sqlite', 'registration.sqlite-journal', 'registration.sqlite-wal', 'registration.sqlite-shm',
                'probe.sqlite', 'probe.sqlite-journal', 'owner', 'first-lock', 'second-attempt'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
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

    public function test_mixed_case_plus_alias_keeps_its_identity_through_verification_and_sign_in(): void
    {
        Notification::fake();
        $response = $this->registerInBrowser([...$this->registrationPayload(), 'email' => '  Gerald+Clinic@GMAIL.COM '])
            ->assertAccepted()->assertJsonMissingPath('email');
        $pending = PendingCustomerRegistration::query()->sole();
        $this->assertSame('Gerald+Clinic@gmail.com', $pending->email);
        $this->postJson('/api/email/verify', ['email' => 'gerald+clinic@gmail.com',
            'registration_token' => $this->authorizedEditCredential($response), 'token' => $this->verificationTokenSentTo($pending)])
            ->assertOk()->assertJsonPath('user.email', 'Gerald+Clinic@gmail.com');
        $this->travel(61)->seconds();
        $this->postJson('/api/sign-in', ['identifier' => 'GERALD+CLINIC@gmail.com', 'password' => 'strong-password'])
            ->assertStatus(202)->assertJsonPath('requires_login_confirmation', true);
    }

    public function test_email_proven_review_preserves_the_verified_legacy_address(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $pending->update(['email' => 'ab@legacy.example']);
        $review = $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending), 'review' => true])->assertOk();
        $payload = [...$this->registrationPayload(), 'email' => 'ab@legacy.example', 'first_name' => 'Corrected',
            'registration_recovery_token' => $review->json('registration_recovery_token')];
        $this->putJson('/api/register/pending', [...$payload, 'email' => 'new@unsupported.example'])->assertUnprocessable();
        $this->assertSame('ab@legacy.example', $pending->fresh()->email);
        $this->putJson('/api/register/pending', $payload)->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'ab@legacy.example', 'first_name' => 'Corrected']);
    }

    public function test_changed_registration_email_cannot_duplicate_an_account_by_local_part_case(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'Registered@gmail.com']);
        $created = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(), 'email' => 'registered@GMAIL.COM',
            'registration_token' => $this->authorizedEditCredential($created)])->assertUnprocessable();
        $this->assertSame('new.customer@gmail.com', PendingCustomerRegistration::query()->sole()->email);
    }

    public function test_legacy_email_still_enters_the_existing_sign_in_challenge(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'ab@legacy.example', 'password_hash' => Hash::make('strong-password')]);
        $this->postJson('/api/sign-in', ['identifier' => 'ab@legacy.example', 'password' => 'strong-password'])
            ->assertAccepted()->assertJsonPath('requires_login_confirmation', true);
    }

    public function test_scanner_get_and_old_signup_codes_cannot_activate_accounts(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $token = $this->verificationTokenSentTo($pending);
        $this->get('/pages/client/verify-email.html?token='.$token)->assertOk();
        $this->getJson('/api/email/verify?token='.$token)->assertStatus(405);
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => '123456'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(hash('sha256', $token), $pending->fresh()->email_verification_token);
    }

    public function test_registration_link_is_single_use_and_does_not_verify_the_phone(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload());
        $pending = PendingCustomerRegistration::sole();
        $token = $this->verificationTokenSentTo($pending);
        $verified = $this->postJson('/api/email/verify', ['token' => $token])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/email/verify', ['token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNull(User::sole()->phone_verified_at);
        $this->withAccountSession($verified)->getJson('/api/me')->assertOk();
    }

    public function test_foreign_browser_must_replace_password_and_cannot_authorize_the_original_browser(): void
    {
        Notification::fake();
        $registration = $this->registerInBrowser($this->registrationPayload());
        $pending = PendingCustomerRegistration::sole();
        $originalCookie = $registration->getCookie('bethlehem_registration_browser', false)->getValue();
        $this->withUnencryptedCookie('bethlehem_registration_browser', str_repeat('f', 64))->withCredentials();
        $review = $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending),
            'registration_token' => $this->authorizedEditCredential($registration)])->assertOk()
            ->assertJsonPath('requires_registration_review', true)->assertJsonMissingPath('token');
        $this->assertNull($pending->fresh()->registration_browser_token_hash);
        $this->withUnencryptedCookie('bethlehem_registration_browser', $originalCookie)->withCredentials();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(),
            'registration_token' => $this->authorizedEditCredential($registration)])->assertUnprocessable();
        $this->postJson('/api/email/registration/session', ['email' => $pending->email])->assertUnauthorized();
        $owner = [...$this->registrationPayload(), 'password' => 'owner-new-password', 'password_confirmation' => 'owner-new-password',
            'registration_recovery_token' => $review->json('registration_recovery_token')];
        $completed = $this->putJson('/api/register/pending', $owner)->assertOk()->assertJsonStructure(['token']);
        $this->assertTrue(Hash::check('owner-new-password', User::sole()->password_hash));
        $this->assertFalse(Hash::check('strong-password', User::sole()->password_hash));
        $this->withAccountSession($completed)->getJson('/api/me')->assertOk();
    }

    public function test_resending_replaces_the_link_but_rejected_resends_preserve_it(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload());
        $pending = PendingCustomerRegistration::sole();
        $old = $this->verificationTokenSentTo($pending);
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted()->assertJsonMissingPath('email_delivery_token');
        $this->assertSame(hash('sha256', $old), $pending->fresh()->email_verification_token);
        $this->travel(60)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $new = $this->verificationTokenSentTo($pending);
        $this->assertNotSame($old, $new);
        $this->postJson('/api/email/verify', ['token' => $old])->assertUnprocessable();
        $this->postJson('/api/email/verify', ['token' => $new])->assertOk();
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

    public function test_handoff_requires_the_actual_cookie_and_a_matching_verified_customer(): void
    {
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload());
        $pending = PendingCustomerRegistration::sole();
        $verified = $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending)])->assertOk();
        $this->postJson('/api/email/registration/session', ['acknowledge' => true])->assertUnauthorized();
        $cookie = $verified->getCookie('bethlehem_account_session', false)->getValue();
        $this->withUnencryptedCookie('bethlehem_account_session', $cookie)->withCredentials();
        $this->postJson('/api/email/registration/session', ['email' => 'other@gmail.com', 'acknowledge' => true])->assertUnauthorized();
        $this->postJson('/api/email/registration/session')->assertOk()->assertJsonPath('original_tab_confirmed', false)->assertJsonMissingPath('token');
        $this->postJson('/api/email/registration/session', ['email' => $pending->email, 'acknowledge' => true])
            ->assertOk()->assertJsonPath('original_tab_confirmed', true)->assertJsonPath('session_token', $verified->json('token'));
        $this->withHeader('Origin', 'https://attacker.example')->postJson('/api/email/registration/session')->assertStatus(419);
    }

    public function test_plaintext_legacy_links_have_only_an_explicit_bounded_review_transition(): void
    {
        $token = str_repeat('b', 64);
        $user = User::factory()->unverified()->create(['email_verification_token' => $token, 'email_verification_expires_at' => now()->addHour()]);
        $this->postJson('/api/email/verify', ['token' => $token])->assertUnprocessable();
        config()->set('authentication.legacy_links_until', now()->addMinutes(5)->toIso8601String());
        $this->postJson('/api/email/verify', ['token' => $token])->assertOk()->assertJsonPath('requires_registration_review', true)->assertJsonMissingPath('token');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_old_queued_signup_notifications_without_magic_tokens_are_suppressed(): void
    {
        $this->useDatabaseMailQueue();
        $pending = PendingCustomerRegistration::create([...$this->registrationPayload(), 'password_hash' => Hash::make('strong-password')]);
        $notification = (new \ReflectionClass(VerifyEmailNotification::class))->newInstanceWithoutConstructor();
        $receipt = \App\Services\AuthenticationMailDelivery::queue($pending, $notification);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame('superseded', \App\Services\AuthenticationMailDelivery::status($receipt));
        $this->assertDatabaseCount('users', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('registrationLinkAges')]
    public function test_registration_links_are_capped_by_the_original_hour(int $age, int $minutes): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $created = $pending->created_at->copy();
        $credential = $this->authorizedEditCredential($response);
        if ($age) {
            $this->travel($age)->minutes();
            $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $credential])->assertOk();
        }
        $pending->refresh();
        $this->assertTrue($pending->created_at->equalTo($created));
        $this->assertSame($minutes * 60, $pending->email_verification_expires_at->timestamp - now()->timestamp);
        $mail = Notification::sent($pending, VerifyEmailNotification::class)->last()->toMail($pending);
        $this->assertContains('This link expires on '.$pending->email_verification_expires_at->copy()->timezone('Asia/Manila')
            ->format('F j, Y, g:i:s A').' (Asia/Manila, UTC+08:00).', $mail->outroLines);
        $this->postJson('/api/register', [...$this->registrationPayload(), 'registration_token' => $credential])
            ->assertAccepted()->assertJsonPath('verification_link_expires_at', $pending->email_verification_expires_at->toIso8601String());
    }

    public static function registrationLinkAges(): array
    {
        return [[0, 30], [15, 30], [30, 30], [45, 15], [50, 10]];
    }

    public function test_registration_can_verify_one_second_before_the_hour_but_not_at_the_deadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $first = PendingCustomerRegistration::sole();
        $this->travel(45)->minutes();
        $this->postJson('/api/email/resend', ['email' => $first->email])->assertAccepted();
        $first->refresh();
        $token = $this->verificationTokenSentTo($first);
        $this->travel(899)->seconds();
        $this->postJson('/api/email/verify', ['token' => $token])->assertOk();
        $this->assertDatabaseCount('users', 1);

        $this->registerInBrowser([...$this->registrationPayload(), 'email' => 'second@gmail.com', 'username' => 'second', 'phone' => null])->assertAccepted();
        $second = PendingCustomerRegistration::sole();
        $this->travel(45)->minutes();
        $this->postJson('/api/email/resend', ['email' => $second->email])->assertAccepted();
        $token = $this->verificationTokenSentTo($second);
        $this->travel(15)->minutes();
        $this->postJson('/api/email/verify', ['token' => $token])->assertUnprocessable()
            ->assertJsonPath('code', 'registration_expired')->assertJsonPath('message', 'This registration has expired. Start again.');
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($second->fresh()->retentionExpired());
    }

    public function test_pending_edit_and_old_stored_link_deadlines_cannot_extend_the_hour(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $credential = $this->authorizedEditCredential($response);
        $created = $pending->created_at->copy();
        $this->travel(45)->minutes();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(), 'first_name' => 'Edited',
            'registration_token' => $credential])->assertUnprocessable();
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $credential])->assertOk();
        $pending->refresh();
        $token = $this->verificationTokenSentTo($pending);
        $this->assertTrue($pending->created_at->equalTo($created));
        // Simulate an issuance stored under the previous deployment's policy.
        $pending->update(['email_verification_expires_at' => now()->addMinutes(30)]);
        $this->postJson('/api/register', [...$this->registrationPayload(), 'registration_token' => $credential])
            ->assertAccepted()->assertJsonPath('verification_link_expires_at', $created->addHour()->toIso8601String());
        $this->travel(15)->minutes();
        $this->postJson('/api/email/verify', ['token' => $token])->assertUnprocessable()->assertJsonPath('code', 'registration_expired');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_proven_review_survives_the_hour_only_until_its_original_grant_expires(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $this->travel(50)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $token = $this->verificationTokenSentTo($pending);
        $this->travel(8)->minutes();
        $this->withUnencryptedCookie('bethlehem_registration_browser', '')->withCredentials();
        $review = $this->postJson('/api/email/verify', ['token' => $token])->assertOk()->assertJsonPath('requires_registration_review', true);
        $deadline = $pending->fresh()->registration_recovery_expires_at;
        $this->travel(1)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $this->assertTrue($pending->fresh()->registration_recovery_expires_at?->equalTo($deadline) ?? false,
            'An unauthenticated resend must not revoke an email-proven review grant.');
        $this->travel(119)->seconds();
        $this->assertSame(0, PendingCustomerRegistration::pruneExpired());
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $this->assertTrue($pending->fresh()->registration_recovery_expires_at->equalTo($deadline));
        $this->postJson('/api/email/verify', ['token' => $token])->assertUnprocessable();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(),
            'registration_recovery_token' => $review->json('registration_recovery_token')])->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
    }

    public function test_expired_review_is_pruned_and_cannot_complete_or_reserve_identifiers(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $this->travel(50)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $this->travel(9)->minutes();
        $this->withUnencryptedCookie('bethlehem_registration_browser', '')->withCredentials();
        $review = $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending)])->assertOk();
        $this->travel(10)->minutes();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(),
            'registration_recovery_token' => $review->json('registration_recovery_token')])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $this->assertNotSame($pending->getKey(), PendingCustomerRegistration::sole()->getKey());
    }

    public function test_delayed_verification_mail_states_the_exact_capped_manila_deadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->useDatabaseMailQueue();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $deadline = $pending->created_at->copy()->addHour()->timezone('Asia/Manila')->format('F j, Y, g:i:s A');
        $this->travel(45)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        // Drop the old issuance, then process the public request, delaying only mail delivery.
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->travel(7)->minutes();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $body = $messages[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString($deadline.' (Asia/Manila, UTC+08:00)', $body);
        $this->assertStringNotContainsString('expires in 30 minutes', $body);
    }

    public function test_nearly_expired_resend_preserves_the_current_link_and_suppresses_late_delivery(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $credential = $this->authorizedEditCredential($response);
        $pending = PendingCustomerRegistration::sole();
        $this->travel(50)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $credential])->assertOk();
        $before = $pending->fresh()->getAttributes();
        $notification = Notification::sent($pending, VerifyEmailNotification::class)->last();
        $this->travel(541)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $credential])->assertUnprocessable()
            ->assertJsonPath('code', 'registration_restart_required');
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertFalse($notification->shouldSend($pending->fresh(), 'mail'));
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $this->assertSame($before, $pending->fresh()->getAttributes());
    }

    public function test_replayed_public_work_cannot_recreate_an_expired_registration(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->useDatabaseMailQueue();
        $this->postJson('/api/register', $this->registrationPayload())->assertAccepted();
        $command = json_decode(DB::table('jobs')->sole()->payload, true)['data']['command'];
        $job = unserialize(app('encrypter')->decrypt($command));
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->travel(60)->minutes();
        $this->assertSame(1, PendingCustomerRegistration::pruneExpired());
        $job->handle(app(\App\Services\CustomerRegistrationService::class));
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('users', 0);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_only_the_bound_edit_credential_can_receive_expired_resend_feedback(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $response = $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $credential = $this->authorizedEditCredential($response);
        $before = $pending->fresh()->getAttributes();
        $this->travel(60)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $credential])
            ->assertUnprocessable()->assertJsonPath('code', 'registration_expired');
        $this->withUnencryptedCookie('bethlehem_registration_browser', str_repeat('x', 64))->withCredentials();
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $credential])->assertAccepted();
        $this->assertSame($before, $pending->fresh()->getAttributes());
    }

    public function test_delayed_initial_public_work_is_discarded_at_one_hour(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->useDatabaseMailQueue();
        $this->postJson('/api/register', $this->registrationPayload())->assertAccepted();
        $this->travel(60)->minutes();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_cleanup_bounds_existing_review_grants_and_ignores_obsolete_retention_configuration(): void
    {
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->registerInBrowser($this->registrationPayload())->assertAccepted();
        $pending = PendingCustomerRegistration::sole();
        $user = User::factory()->create();
        config()->set('app.pending_registration_retention_hours', 48);
        $this->travel(50)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertAccepted();
        $this->travel(9)->minutes();
        $this->withUnencryptedCookie('bethlehem_registration_browser', '')->withCredentials();
        $review = $this->postJson('/api/email/verify', ['token' => $this->verificationTokenSentTo($pending)])->assertOk();
        // An old/malformed stored grant cannot reserve identifiers indefinitely.
        $pending->update(['registration_recovery_expires_at' => now()->addDay()]);
        $this->travel(1)->minutes();
        $this->assertTrue($pending->fresh()->retentionExpired());
        $this->assertSame(0, PendingCustomerRegistration::pruneExpired());
        $this->travel(10)->minutes();
        $this->assertSame(1, PendingCustomerRegistration::pruneExpired());
        $this->putJson('/api/register/pending', [...$this->registrationPayload(),
            'registration_recovery_token' => $review->json('registration_recovery_token')])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    // Pre-existing authorized contexts are fixtures, never public API output.
    private ?\WeakMap $editCredentials = null;

    private function authorizedEditCredential($response): ?string
    {
        if ($token = $response->json('registration_token')) return $token;
        $this->editCredentials ??= new \WeakMap;
        if (isset($this->editCredentials[$response])) return $this->editCredentials[$response];
        $pending = PendingCustomerRegistration::query()->first();
        if (! $pending) return null;
        $token = str_repeat('e', 64);
        if ($pending->registration_edit_token_hash) $pending->update(['registration_edit_token_hash' => hash('sha256', $token)]);
        return $this->editCredentials[$response] = $token;
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
        $response = $this->postJson('/api/register', $payload);
        if ($cookie = $response->getCookie('bethlehem_registration_browser', false)) {
            $this->withUnencryptedCookie('bethlehem_registration_browser', $cookie->getValue())->withCredentials();
        }
        // Exercise the new public processing stage before testing the mail stage.
        if ($response->status() === 202 && config('queue.default') === 'database') {
            $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
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
}
