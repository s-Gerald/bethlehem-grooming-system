<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerPhoneVerificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['services.sms_verification.available' => true, 'services.sms_verification.enforced' => false,
            'services.iprogsms.api_token' => 'test-token', 'app.key' => 'test-key']);
        Http::preventStrayRequests();
        Schema::create('users', function (Blueprint $t) {
            $t->increments('user_id');
            $t->string('role')->default('customer');
            $t->string('phone', 20)->nullable()->unique();
            $t->timestamp('phone_verified_at')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_archived')->default(false);
        });
        $path = base_path('database/migrations/2026_10_22_000002_create_phone_verification_attempts.php');
        (require $path)->up();
        $this->customer = User::create(['role' => 'customer', 'email_verified_at' => now()]);
        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('phone_verification_attempts');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function fakeSend(string $code = '123456'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/send_otp' => Http::response(['status' => 'success', 'message' => 'OTP sent successfully', 'data' => [
            'phone_number' => '09171234567', 'otp_code' => $code, 'otp_code_confirmed' => false,
            'otp_code_expires_at' => now()->addMinutes(5)->toIso8601String(), 'message' => "Your OTP is $code",
        ]]), '*/verify_otp' => Http::response(['status' => 'success', 'message' => 'OTP verified successfully'])]);
    }

    private function send(): string
    {
        return $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])
            ->assertOk()->assertJsonPath('success', true)->json('challenge_id');
    }

    private function verify(string $id, string $code = '123456')
    {
        return $this->postJson('/api/phone-verification/verify', ['challenge_id' => $id, 'code' => $code]);
    }

    public function test_send_and_verify_use_dedicated_provider_and_persist_only_after_proof(): void
    {
        $this->fakeSend();
        $response = $this->postJson('/api/phone-verification/send', ['phone' => '+63 917-123-4567']);
        $response->assertOk()->assertJsonPath('expires_in', 300)->assertJsonPath('resend_after', 60);
        $this->assertNull($this->customer->fresh()->phone);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
        $this->assertStringNotContainsString('123456', $response->getContent());
        $this->assertStringNotContainsString('test-token', $response->getContent());
        $id = $response->json('challenge_id');
        $this->verify($id)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('09171234567', $this->customer->fresh()->phone);
        $this->assertNotNull($this->customer->fresh()->phone_verified_at);
        Http::assertSent(fn ($r) => $r->url() === 'https://www.iprogsms.com/api/v1/otp/send_otp'
            && $r['api_token'] === 'test-token' && $r['phone_number'] === '09171234567' && $r['expires_in_minutes'] === 5);
        Http::assertSent(fn ($r) => $r->url() === 'https://www.iprogsms.com/api/v1/otp/verify_otp'
            && $r['api_token'] === 'test-token' && $r['phone_number'] === '09171234567' && $r['otp'] === '123456');
        Http::assertSentCount(2);
        $this->verify($id)->assertStatus(409);
        Http::assertSentCount(2);
    }

    public function test_cooldown_prevents_duplicate_sends_and_resends(): void
    {
        $this->fakeSend();
        $id = $this->send();
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(429)->assertJsonPath('resend_after', 60);
        $this->postJson('/api/phone-verification/resend', ['challenge_id' => $id])->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_resend_supersedes_generation_and_retains_failed_attempt_budget(): void
    {
        $this->fakeSend();
        $id = $this->send();
        for ($i = 0; $i < 4; $i++) {
            $this->verify($id, '000000')->assertUnprocessable();
        }
        $this->travel(60)->seconds();
        $this->fakeSend('654321');
        $next = $this->postJson('/api/phone-verification/resend', ['challenge_id' => $id])->assertOk()->json('challenge_id');
        $this->assertNotSame($id, $next);
        $this->verify($id)->assertStatus(409);
        $this->verify($next, '123456')->assertUnprocessable();
        $this->verify($next, '654321')->assertStatus(429);
        $this->travel(60)->seconds();
        $this->postJson('/api/phone-verification/resend', ['challenge_id' => $next])->assertStatus(429);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public function test_expired_and_foreign_challenges_cannot_verify(): void
    {
        $this->fakeSend();
        $id = $this->send();
        $other = User::create(['role' => 'customer', 'email_verified_at' => now()]);
        Sanctum::actingAs($other);
        $this->verify($id)->assertStatus(409);
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(409);
        Sanctum::actingAs($this->customer);
        $this->travel(301)->seconds();
        $this->verify($id)->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_duplicate_and_invalid_numbers_do_not_contact_provider(): void
    {
        Http::fake();
        User::create(['phone' => '09171234567']);
        foreach (['09171234567', '123', ['09171234567']] as $phone) {
            $this->postJson('/api/phone-verification/send', ['phone' => $phone])->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_provider_failure_or_malformed_success_never_verifies(): void
    {
        $this->fakeSend();
        $id = $this->send();
        foreach ([['status' => 'error', 'message' => 'secret 123456'], ['status' => 200], [], ['message' => 'OTP verified successfully']] as $payload) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*/verify_otp' => Http::response($payload)]);
            $response = $this->verify($id);
            $response->assertStatus(503);
            $this->assertStringNotContainsString('secret', $response->getContent());
            $this->assertNull($this->customer->fresh()->phone_verified_at);
        }
    }

    public function test_account_or_destination_changes_during_provider_request_prevent_commit(): void
    {
        $this->fakeSend();
        $id = $this->send();
        Http::fake(['*/verify_otp' => function () {
            DB::table('users')->where('user_id', $this->customer->getKey())->update(['phone' => '09991234567']);

            return Http::response(['status' => 'success', 'message' => 'OTP verified successfully']);
        }]);
        $this->verify($id)->assertStatus(409);
        $this->assertSame('09991234567', $this->customer->fresh()->phone);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public function test_missing_configuration_and_unavailable_sms_make_no_requests(): void
    {
        Http::fake();
        config(['services.iprogsms.api_token' => null]);
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(503);
        config(['services.iprogsms.api_token' => 'test-token', 'services.sms_verification.available' => false]);
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_staff_cannot_use_customer_verification(): void
    {
        Http::fake();
        $this->customer->forceFill(['role' => 'admin'])->save();
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_both_customer_submission_routes_fail_closed_when_enforced(): void
    {
        foreach ([false, true] as $available) {
            config(['services.sms_verification.available' => $available, 'services.sms_verification.enforced' => true]);
            foreach (['/api/booking/store', '/api/clinic/pre-register'] as $url) {
                $this->postJson($url, ['phone_verified' => true])->assertForbidden()->assertJsonPath('code', 'phone_verification_required');
            }
        }
    }

    public function test_provider_success_can_be_reconciled_after_local_commit_failure_even_on_last_attempt(): void
    {
        $this->fakeSend();
        $id = $this->send();
        for ($i = 0; $i < 4; $i++) {
            $this->verify($id, '000000')->assertUnprocessable();
        }
        DB::unprepared("CREATE TRIGGER reject_phone_save BEFORE UPDATE OF phone ON users BEGIN SELECT RAISE(ABORT, 'fixture save failure'); END");
        $this->verify($id)->assertStatus(503);
        $this->assertNull($this->customer->fresh()->phone);
        $this->assertSame('confirmed', DB::table('phone_verification_attempts')->value('status'));
        DB::unprepared('DROP TRIGGER reject_phone_save');
        $this->verify($id)->assertOk();
        $this->assertNotNull($this->customer->fresh()->phone_verified_at);
        Http::assertSentCount(2);
        $this->verify($id)->assertStatus(409);
    }

    public function test_local_transaction_rolls_back_phone_when_consuming_proof_fails(): void
    {
        $this->fakeSend();
        $id = $this->send();
        DB::unprepared("CREATE TRIGGER reject_consumption BEFORE UPDATE OF status ON phone_verification_attempts WHEN NEW.status = 'consumed' BEGIN SELECT RAISE(ABORT, 'fixture consumption failure'); END");
        $this->verify($id)->assertStatus(503);
        $this->assertNull($this->customer->fresh()->phone);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
        $this->assertSame('confirmed', DB::table('phone_verification_attempts')->value('status'));
        DB::unprepared('DROP TRIGGER reject_consumption');
        $this->verify($id)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_process_local_cache_is_rejected_outside_isolated_tests(): void
    {
        Http::fake();
        $this->app->instance('env', 'production');
        try {
            app(CustomerPhoneVerificationService::class)->send($this->customer, '09171234567', '127.0.0.1');
            $this->fail('Process-local cache must prevent provider access.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_phone_change_and_change_back_invalidate_old_proof(): void
    {
        $this->fakeSend();
        $id = $this->send();
        $this->customer->phone = '09991234567';
        $this->customer->save();
        $this->customer->phone = null;
        $this->customer->save();
        $this->verify($id)->assertStatus(409);
        Http::assertSentCount(1);
        DB::table('users')->where('user_id', $this->customer->getKey())->update(['phone' => '09171234567', 'phone_verified_at' => now()]);
        $this->customer->refresh()->forceFill(['phone' => '09991234567'])->save();
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public function test_duplicate_number_claimed_during_provider_call_cannot_be_saved(): void
    {
        $this->fakeSend();
        $id = $this->send();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/verify_otp' => function () {
            User::create(['phone' => '09171234567']);

            return Http::response(['status' => 'success', 'message' => 'OTP verified successfully']);
        }]);
        $this->verify($id)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertNull($this->customer->fresh()->phone);
    }

    public function test_overlapping_operations_do_not_send_duplicates_or_hold_database_transactions(): void
    {
        Http::fake(['*/send_otp' => function () {
            $this->assertSame(0, DB::transactionLevel());
            $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(409);
            $id = DB::table('phone_verification_attempts')->value('generation');
            $this->postJson('/api/phone-verification/resend', ['challenge_id' => $id])->assertStatus(409);
            $this->verify($id)->assertStatus(409);

            return Http::response(['status' => 'success', 'data' => ['phone_number' => '09171234567',
                'otp_code' => '123456', 'otp_code_confirmed' => false, 'otp_code_expires_at' => now()->addMinutes(5)->toIso8601String()]]);
        }]);
        $this->send();
        Http::assertSentCount(1);
    }

    public function test_superseded_provider_response_cannot_finalize_a_new_generation(): void
    {
        $this->fakeSend();
        $id = $this->send();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/verify_otp' => function () {
            DB::table('phone_verification_attempts')->update(['generation' => (string) Str::uuid(), 'status' => 'active']);

            return Http::response(['status' => 'success', 'message' => 'OTP verified successfully']);
        }]);
        $this->verify($id)->assertStatus(409);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    #[DataProvider('badSendResponses')]
    public function test_failed_sends_are_sanitized_and_not_retried(array $payload, int $status): void
    {
        Http::fake(['*' => Http::response($payload, $status)]);
        $response = $this->postJson('/api/phone-verification/send', ['phone' => '09171234567']);
        $response->assertStatus(503);
        foreach (['test-token', '123456', 'sensitive provider'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertNull($this->customer->fresh()->phone_verified_at);
        Http::assertSentCount(1);
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(429);
        Http::assertSentCount(1);
    }

    public static function badSendResponses(): array
    {
        return [
            'http error' => [['status' => 'success', 'message' => 'sensitive provider 123456'], 500],
            'provider error' => [['status' => 'error', 'message' => 'sensitive provider 123456'], 200],
            'missing data' => [['status' => 'success'], 200],
            'wrong status type' => [['status' => 200], 200],
            'not an object' => [[], 200],
            'invalid expiry' => [['status' => 'success', 'data' => ['phone_number' => '09171234567', 'otp_code' => '123456', 'otp_code_confirmed' => false, 'otp_code_expires_at' => 'invalid']], 200],
            'wrong destination' => [['status' => 'success', 'data' => ['phone_number' => '09991234567', 'otp_code' => '123456', 'otp_code_confirmed' => false, 'otp_code_expires_at' => '2026-10-22T12:00:00Z']], 200],
        ];
    }

    public function test_connection_failure_has_no_retry_and_no_sensitive_exception_output(): void
    {
        Http::fake(fn () => throw new ConnectionException('test-token sensitive provider 123456'));
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(503)->assertDontSee('test-token')->assertDontSee('123456');
        $this->assertNull($this->customer->fresh()->phone_verified_at);
        $this->assertSame('uncertain', DB::table('phone_verification_attempts')->value('status'));
    }

    public function test_account_disabled_during_provider_verification_cannot_commit(): void
    {
        $this->fakeSend();
        $id = $this->send();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/verify_otp' => function () {
            DB::table('users')->update(['is_active' => false]);

            return Http::response(['status' => 'success', 'message' => 'OTP verified successfully']);
        }]);
        $this->verify($id)->assertForbidden();
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public function test_verification_uses_bound_phone_and_authenticated_user_despite_extra_payload(): void
    {
        $this->fakeSend();
        $id = $this->send();
        $other = User::create(['phone' => '09991234567']);
        $this->postJson('/api/phone-verification/verify', ['challenge_id' => $id, 'code' => '123456',
            'phone' => '09991234567', 'user_id' => $other->getKey()])->assertOk();
        $this->assertSame('09171234567', $this->customer->fresh()->phone);
        $this->assertNull($other->fresh()->phone_verified_at);
    }

    public function test_six_digit_validation_and_pending_account_conflict_precede_provider_calls(): void
    {
        $this->fakeSend();
        $id = $this->send();
        foreach (['12345', '1234567', 'abcdef', 123456, ['123456']] as $code) {
            $this->postJson('/api/phone-verification/verify', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
        }
        Http::assertSentCount(1);
        Schema::create('pending_customer_registrations', function (Blueprint $t) {
            $t->id();
            $t->string('phone');
        });
        DB::table('pending_customer_registrations')->insert(['phone' => '09991234567']);
        $this->travel(60)->seconds();
        $this->postJson('/api/phone-verification/send', ['phone' => '09991234567'])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    #[DataProvider('sendLimitSettings')]
    public function test_each_configurable_send_budget_is_enforced(string $setting): void
    {
        config(['services.sms_verification.'.$setting => 1]);
        $this->fakeSend();
        $this->send();
        $this->travel(60)->seconds();
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(429)->assertJsonPath('code', 'sms_send_limit');
        Http::assertSentCount(1);
    }

    public static function sendLimitSettings(): array
    {
        return array_map(fn ($v) => [$v], ['user_hourly_limit', 'phone_hourly_limit', 'ip_hourly_limit', 'global_hourly_limit', 'global_daily_limit']);
    }

    public function test_disabled_and_available_only_flags_preserve_submission_validation(): void
    {
        foreach ([false, true] as $available) {
            config(['services.sms_verification.available' => $available, 'services.sms_verification.enforced' => false]);
            foreach (['/api/booking/store', '/api/clinic/pre-register'] as $url) {
                $this->postJson($url, [])->assertUnprocessable();
            }
        }
    }

    public function test_verified_customer_and_staff_are_not_blocked_by_phone_middleware(): void
    {
        config(['services.sms_verification.enforced' => true, 'services.sms_verification.available' => false]);
        DB::table('users')->update(['phone' => '09171234567', 'phone_verified_at' => now()]);
        Sanctum::actingAs($this->customer->fresh());
        foreach (['/api/booking/store', '/api/clinic/pre-register'] as $url) {
            $this->postJson($url, [])->assertUnprocessable();
        }
        $this->customer->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/booking/store', [])->assertUnprocessable();
    }

    public function test_unauthenticated_and_disabled_accounts_cannot_send(): void
    {
        Http::fake();
        $this->app['auth']->forgetGuards();
        foreach (['send', 'verify', 'resend'] as $action) {
            $this->postJson('/api/phone-verification/'.$action, [])->assertUnauthorized();
        }
        foreach (['is_active' => false, 'is_archived' => true, 'email_verified_at' => null] as $field => $value) {
            $user = User::create(['role' => 'customer', 'email_verified_at' => now(), $field => $value]);
            Sanctum::actingAs($user);
            $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    #[DataProvider('concurrentOperations')]
    public function test_separate_processes_serialize_send_resend_and_verify(string $operation): void
    {
        $this->fakeSend();
        if ($operation !== 'send') {
            $this->send();
            if ($operation === 'resend') {
                DB::table('phone_verification_attempts')->update(['resend_at' => now()->subSecond()]);
            }
        }
        User::create(['role' => 'customer', 'email_verified_at' => now()]);
        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
        $directory = sys_get_temp_dir().'/bethlehem-sms-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/sms.sqlite';
        $owner = bin2hex(random_bytes(16));
        file_put_contents($directory.'/owner', $owner);
        $first = $second = $connection = null;
        try {
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                [$script, $database, $role, $operation, $owner] = $argv;
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-sms-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'sms.sqlite' || !hash_equals(file_get_contents($directory.'/owner'), $owner)) {
                    throw new RuntimeException('An owned disposable SMS fixture database is required.');
                }
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'cache.default' => 'database', 'cache.prefix' => 'sms-test-'.$owner,
                    'cache.stores.database.connection' => 'sqlite', 'cache.stores.database.lock_connection' => 'sqlite',
                    'cache.stores.database.table' => 'cache', 'cache.stores.database.lock_table' => 'cache_locks',
                    'services.sms_verification.available' => true, 'services.iprogsms.api_token' => 'test-token', 'app.key' => 'test-key']);
                Illuminate\Support\Facades\DB::purge('sqlite');
                $connection = Illuminate\Support\Facades\DB::connection();
                if ($connection->getDriverName() !== 'sqlite' || realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) {
                    throw new RuntimeException('Unexpected worker database.');
                }
                $wait = function ($file) {
                    $deadline = microtime(true) + 8;
                    do { clearstatcache(); if (is_file($file)) return; usleep(1000); } while (microtime(true) < $deadline);
                    throw new RuntimeException('SMS worker barrier timeout.');
                };
                Illuminate\Support\Facades\Http::preventStrayRequests();
                Illuminate\Support\Facades\Http::fake(function ($request) use ($directory, $wait, $connection) {
                    if ($connection->transactionLevel() !== 0) throw new RuntimeException('Database transaction held during HTTP.');
                    file_put_contents($directory.'/provider-calls', "request\n", FILE_APPEND | LOCK_EX);
                    touch($directory.'/provider-started');
                    $wait($directory.'/contender-finished');
                    if (str_ends_with($request->url(), 'verify_otp')) {
                        return Illuminate\Support\Facades\Http::response(['status' => 'success', 'message' => 'OTP verified successfully']);
                    }
                    return Illuminate\Support\Facades\Http::response(['status' => 'success', 'data' => [
                        'phone_number' => '09171234567', 'otp_code' => '123456', 'otp_code_confirmed' => false,
                        'otp_code_expires_at' => now()->addMinutes(5)->toIso8601String()]]);
                });
                $service = app(App\Services\CustomerPhoneVerificationService::class);
                $user = App\Models\User::findOrFail(1);
                $generation = $connection->table('phone_verification_attempts')->value('generation');
                $call = function ($action, $user) use ($service, &$generation) {
                    try {
                        match ($action) {
                            'send' => $service->send($user, '09171234567', '127.0.0.1'),
                            'resend' => $service->resend($user, $generation, '127.0.0.1'),
                            'verify' => $service->verify($user, $generation, '123456'),
                        };
                        return 200;
                    } catch (Illuminate\Http\Exceptions\HttpResponseException $e) { return $e->getResponse()->getStatusCode(); }
                };
                if ($role === 'contender') {
                    $wait($directory.'/provider-started');
                    $generation = $connection->table('phone_verification_attempts')->value('generation');
                    $results = [];
                    foreach (['send', 'resend', 'verify'] as $action) $results[] = $call($action, $user);
                    $results[] = $call('send', App\Models\User::findOrFail(2));
                    touch($directory.'/contender-finished');
                } else { $results = [$call($operation, $user)]; }
                echo json_encode(['pid' => getmypid(), 'statuses' => $results], JSON_THROW_ON_ERROR);
                PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            $first = new Process([PHP_BINARY, '-r', $worker, $database, 'first', $operation, $owner], base_path(), $environment);
            $second = new Process([PHP_BINARY, '-r', $worker, $database, 'contender', $operation, $owner], base_path(), $environment);
            $first->setTimeout(15);
            $second->setTimeout(15);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput().$first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput().$second->getErrorOutput());
            $a = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($a['pid'], $b['pid']);
            $this->assertSame([200], $a['statuses']);
            $this->assertSame([409, 409, 409, 409], $b['statuses']);
            $this->assertSame("request\n", file_get_contents($directory.'/provider-calls'));
            $connection = new \PDO('sqlite:'.$database);
            $this->assertSame($operation === 'verify' ? 'consumed' : 'active', $connection->query('SELECT status FROM phone_verification_attempts')->fetchColumn());
            $this->assertSame($operation === 'verify' ? 1 : 0, (int) $connection->query('SELECT COUNT(*) FROM users WHERE phone_verified_at IS NOT NULL')->fetchColumn());
        } finally {
            $first?->stop();
            $second?->stop();
            $connection = null;
            // Only the known files in this owned test directory are removed.
            foreach (['sms.sqlite', 'sms.sqlite-journal', 'sms.sqlite-wal', 'sms.sqlite-shm', 'owner', 'provider-calls', 'provider-started', 'contender-finished'] as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            rmdir($directory);
            Schema::dropIfExists('cache_locks');
            Schema::dropIfExists('cache');
        }
    }

    public static function concurrentOperations(): array
    {
        return [['send'], ['resend'], ['verify']];
    }
}
