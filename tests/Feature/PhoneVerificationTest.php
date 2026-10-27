<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerPhoneVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    private User $customer;
    private array $smsDiagnostics = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['services.sms_verification.available' => true, 'services.sms_verification.enforced' => false,
            'services.iprogsms.api_token' => 'test-token', 'app.key' => 'test-key']);
        Http::preventStrayRequests();
        Log::listen(function (\Illuminate\Log\Events\MessageLogged $event) {
            if ($event->message === 'iProgSMS OTP failure') $this->smsDiagnostics[] = $event->context;
        });
        Schema::create('users', function (Blueprint $t) {
            $t->increments('user_id');
            $t->string('role')->default('customer');
            $t->string('phone', 20)->nullable()->unique();
            $t->string('password_hash')->nullable();
            $t->string('email')->nullable();
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

    public function test_pending_migration_supports_local_mysql_timestamp_defaults(): void
    {
        if (getenv('PHONE_VERIFICATION_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Opt in with PHONE_VERIFICATION_MYSQL_TEST=1 to create an owned disposable local MySQL database.');
        }

        require_once __DIR__.'/../Support/CapacityMysqlHarness.php';
        $settings = \Tests\Support\capacityMysqlSettings(getenv());
        $server = new \PDO("mysql:host={$settings['host']};port={$settings['port']};charset=utf8mb4",
            $settings['username'], $settings['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
        $identity = $server->query('SELECT @@hostname AS hostname, @@port AS port')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(strtolower(explode('.', gethostname())[0]), strtolower(explode('.', $identity['hostname'])[0]));
        $this->assertSame((int) $settings['port'], (int) $identity['port']);
        $database = 'bethlehem_sms_schema_'.bin2hex(random_bytes(6));
        $this->assertMatchesRegularExpression('/\Abethlehem_sms_schema_[a-f0-9]{12}\z/', $database);
        $schema = Schema::getFacadeRoot();
        $created = false;
        try {
            // No IF NOT EXISTS: only a successfully created, randomly named schema is owned.
            $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;
            config(['database.connections.phone_schema_mysql' => [
                'driver' => 'mysql', 'host' => $settings['host'], 'port' => $settings['port'],
                'database' => $database, 'username' => $settings['username'], 'password' => $settings['password'],
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ]]);
            $connection = DB::connection('phone_schema_mysql');
            $this->assertSame($database, $connection->selectOne('SELECT DATABASE() AS name')->name);
            $builder = $connection->getSchemaBuilder();
            Schema::swap($builder);
            $migration = require database_path('migrations/2026_10_22_000002_create_phone_verification_attempts.php');
            $migration->up();
            $this->assertTrue($builder->hasTable('phone_verification_attempts'));
            $columns = collect($connection->select('SHOW COLUMNS FROM phone_verification_attempts'))->keyBy('Field');
            foreach (['expires_at', 'resend_at', 'attempts_reset_at'] as $name) {
                $this->assertSame('datetime', $columns[$name]->Type);
                $this->assertSame('NO', $columns[$name]->Null);
                $this->assertNull($columns[$name]->Default);
            }
            foreach (['original_verified_at', 'created_at', 'updated_at'] as $name) {
                $this->assertSame('YES', $columns[$name]->Null);
                $this->assertNull($columns[$name]->Default);
                $this->assertSame('', $columns[$name]->Extra);
            }
            $indexes = collect($builder->getIndexes('phone_verification_attempts'));
            foreach (['user_id', 'generation'] as $name) {
                $this->assertTrue($indexes->first(fn ($index) => $index['columns'] === [$name])['unique']);
            }
            $this->assertFalse($indexes->first(fn ($index) => $index['columns'] === ['phone'])['unique']);
            $time = Carbon::parse('2026-10-22 12:00:00', config('app.timezone'));
            $values = ['user_id' => 1, 'phone' => '09171234567', 'generation' => (string) Str::uuid(),
                'status' => 'active', 'expires_at' => $time->copy()->addMinutes(5), 'resend_at' => $time->copy()->addSeconds(60),
                'attempts_reset_at' => $time->copy()->addHour(), 'created_at' => $time, 'updated_at' => $time];
            $connection->table('phone_verification_attempts')->insert($values);
            $row = $connection->table('phone_verification_attempts')->first();
            $this->assertSame('2026-10-22 12:05:00', $row->expires_at);
            $this->assertSame('2026-10-22 12:01:00', $row->resend_at);
            $this->assertSame('2026-10-22 13:00:00', $row->attempts_reset_at);
            $this->assertSame($time->copy()->addMinutes(5)->getTimestamp(), Carbon::parse($row->expires_at, config('app.timezone'))->getTimestamp());
            $this->assertNull($row->original_verified_at);
            $this->assertSame(0, $row->failed_attempts);
            foreach (['expires_at', 'resend_at', 'attempts_reset_at'] as $name) {
                try {
                    $connection->table('phone_verification_attempts')->insert([
                        ...$values, 'user_id' => 2, 'generation' => (string) Str::uuid(), $name => null,
                    ]);
                    $this->fail("{$name} must remain required.");
                } catch (QueryException $exception) {
                    $this->assertSame(1048, $exception->errorInfo[1]);
                }
            }
            $this->assertTrue($connection->table('phone_verification_attempts')->where('expires_at', '>', $time->copy()->addSeconds(299))->exists());
            $this->assertFalse($connection->table('phone_verification_attempts')->where('expires_at', '>', $time->copy()->addMinutes(5))->exists());
            $this->assertTrue($connection->table('phone_verification_attempts')->where('resend_at', '>', $time->copy()->addSeconds(59))->exists());
            $this->assertFalse($connection->table('phone_verification_attempts')->where('resend_at', '>', $time->copy()->addSeconds(60))->exists());
            $connection->table('phone_verification_attempts')->where('user_id', 1)->update(['status' => 'consumed']);
            $this->assertEquals($row->created_at, $connection->table('phone_verification_attempts')->value('created_at'));
            $this->assertEquals($row->updated_at, $connection->table('phone_verification_attempts')->value('updated_at'));
            $migration->down();
            $this->assertFalse($builder->hasTable('phone_verification_attempts'));
            $migration->up();
            $this->assertSame(0, $connection->table('phone_verification_attempts')->count());
            $connection->table('phone_verification_attempts')->insert($values);
            $this->assertSame(1, $connection->table('phone_verification_attempts')->count());
            $completionMigration = require database_path('migrations/2026_10_25_000004_create_grooming_completion_sms.php');
            $completionMigration->up();
            $completionIndexes = collect($builder->getIndexes('grooming_completion_sms'));
            $this->assertTrue($completionIndexes->first(fn ($index) => $index['columns'] === ['booking_id'])['unique']);
            $this->assertNotNull($completionIndexes->first(fn ($index) => $index['columns'] === ['status', 'queued_at']));
            $completionValues = ['booking_id' => 1, 'user_id' => 1, 'status' => 'pending', 'created_at' => $time, 'updated_at' => $time];
            $connection->table('grooming_completion_sms')->insert($completionValues);
            try {
                $connection->table('grooming_completion_sms')->insert($completionValues);
                $this->fail('MySQL must reject duplicate completion events.');
            } catch (QueryException $exception) {
                $this->assertSame(1062, $exception->errorInfo[1]);
            }
            $this->assertSame(1, $connection->table('grooming_completion_sms')->count());
            $this->assertSame('2026-10-22 12:00:00', $connection->table('grooming_completion_sms')->value('created_at'));
            $completionMigration->down();
            $this->assertFalse($builder->hasTable('grooming_completion_sms'));
            Http::assertNothingSent();
        } finally {
            Schema::swap($schema);
            DB::purge('phone_schema_mysql');
            if ($created) {
                $server->exec("DROP DATABASE `{$database}`");
                $check = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
                $check->execute([$database]);
                $this->assertSame(0, (int) $check->fetchColumn());
            }
        }
    }

    private function fakeSend(string $code = '123456', string $phone = '09171234567'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/send_otp' => Http::response(['status' => 'success', 'message' => 'OTP sent successfully', 'data' => [
            'phone_number' => $phone, 'otp_code' => $code, 'otp_code_confirmed' => false,
            'otp_code_expires_at' => now()->addMinutes(5)->toIso8601String(), 'message' => "Your OTP is $code",
        ]]), '*/verify_otp' => Http::response(['status' => 'success', 'message' => 'OTP verified successfully'])]);
    }

    public function test_reopening_recovers_safe_challenge_without_sending(): void
    {
        $this->fakeSend();
        $id = $this->send();
        $this->travel(20)->seconds();
        $state = $this->getJson('/api/phone-verification/state')->assertOk()
            ->assertJsonPath('challenge.challenge_id', $id)->assertJsonPath('challenge.expires_in', 280)
            ->assertJsonPath('challenge.resend_after', 40)->assertJsonPath('challenge.can_verify', true);
        foreach (['123456', 'test-token', '09171234567', 'fingerprint', 'original_phone'] as $secret) {
            $state->assertDontSee($secret);
        }
        Http::assertSentCount(1);
        $this->travel(281)->seconds();
        $this->getJson('/api/phone-verification/state')->assertOk()
            ->assertJsonPath('challenge.status', 'expired')->assertJsonPath('challenge.can_verify', false);
    }

    public function test_replacing_verified_phone_requires_password_and_commits_only_after_otp(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        DB::table('users')->update(['phone' => '09991234567', 'phone_verified_at' => now(),
            'password_hash' => \Illuminate\Support\Facades\Hash::make('Current-password-123'), 'email' => 'owner@gmail.com']);
        $this->fakeSend();
        foreach ([null, 'incorrect'] as $password) {
            $this->postJson('/api/phone-verification/send', ['phone' => '09171234567', 'current_password' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        Http::assertNothingSent();
        $id = $this->postJson('/api/phone-verification/send', ['phone' => '09171234567', 'current_password' => 'Current-password-123'])
            ->assertOk()->json('challenge_id');
        $this->assertSame('09991234567', $this->customer->fresh()->phone);
        $this->assertNotNull($this->customer->fresh()->phone_verified_at);
        $this->verify($id, '000000')->assertUnprocessable();
        $this->assertSame('09991234567', $this->customer->fresh()->phone);
        $this->verify($id)->assertOk()->assertJsonPath('message', 'Phone number updated.');
        $this->assertSame('09171234567', $this->customer->fresh()->phone);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->customer->fresh(), \App\Notifications\PhoneNumberChangedNotification::class);
    }

    public function test_canceled_replacement_keeps_old_phone_and_cooldown(): void
    {
        DB::table('users')->update(['phone' => '09991234567', 'phone_verified_at' => now(),
            'password_hash' => \Illuminate\Support\Facades\Hash::make('Current-password-123')]);
        $this->fakeSend();
        $id = $this->postJson('/api/phone-verification/send', ['phone' => '09171234567', 'current_password' => 'Current-password-123'])
            ->assertOk()->json('challenge_id');
        $this->postJson('/api/phone-verification/cancel', ['challenge_id' => $id])->assertOk();
        $this->verify($id)->assertStatus(409);
        $this->getJson('/api/phone-verification/state')->assertOk()->assertJsonPath('challenge', null);
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567', 'current_password' => 'Current-password-123'])->assertStatus(429);
        $this->assertSame('09991234567', $this->customer->fresh()->phone);
        $this->assertNotNull($this->customer->fresh()->phone_verified_at);
        Http::assertSentCount(1);
    }

    public function test_already_verified_number_does_not_send_another_code(): void
    {
        DB::table('users')->update(['phone' => '09171234567', 'phone_verified_at' => now()]);
        Http::fake();
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_replacement_resend_retains_password_authorization_but_rejects_changed_ownership(): void
    {
        DB::table('users')->update(['phone' => '09991234567', 'phone_verified_at' => now(),
            'password_hash' => \Illuminate\Support\Facades\Hash::make('Current-password-123')]);
        $this->fakeSend();
        $id = $this->postJson('/api/phone-verification/send', ['phone' => '09171234567', 'current_password' => 'Current-password-123'])
            ->assertOk()->json('challenge_id');
        $this->travel(60)->seconds();
        $next = $this->postJson('/api/phone-verification/resend', ['challenge_id' => $id])->assertOk()->json('challenge_id');
        $this->assertNotSame($id, $next);
        $this->assertSame('09991234567', $this->customer->fresh()->phone);
        DB::table('users')->update(['phone' => '09999876543']);
        $this->travel(60)->seconds();
        $this->postJson('/api/phone-verification/resend', ['challenge_id' => $next])->assertStatus(409);
        Http::assertSentCount(2);
    }

    public function test_replacement_password_attempts_are_account_scoped_across_destinations(): void
    {
        DB::table('users')->update(['phone' => '09991234567', 'phone_verified_at' => now(),
            'password_hash' => \Illuminate\Support\Facades\Hash::make('Current-password-123')]);
        Http::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/phone-verification/send', ['phone' => '0917123456'.$i, 'current_password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567', 'current_password' => 'Current-password-123'])->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_email_queue_failure_does_not_undo_successful_replacement(): void
    {
        DB::table('users')->update(['phone' => '09991234567', 'phone_verified_at' => now(),
            'password_hash' => \Illuminate\Support\Facades\Hash::make('Current-password-123'), 'email' => 'owner@gmail.com']);
        $this->fakeSend();
        $id = $this->postJson('/api/phone-verification/send', ['phone' => '09171234567', 'current_password' => 'Current-password-123'])
            ->assertOk()->json('challenge_id');
        $this->assertFalse(Schema::hasTable('jobs'));
        $this->verify($id)->assertOk()->assertJsonPath('message', 'Phone number updated.');
        $this->assertSame('09171234567', $this->customer->fresh()->phone);
        $this->assertNotNull($this->customer->fresh()->phone_verified_at);
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
            && $r['api_token'] === 'test-token' && $r['phone_number'] === '09171234567' && $r['expires_in_minutes'] === 5
            && $r['message'] === "Bethlehem Animal Clinic\nYour verification code is :otp. Valid for 5 minutes.\nDo not share.");
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

    public function test_switching_candidate_preserves_cooldown_and_invalidates_old_proof(): void
    {
        $this->fakeSend();
        $old = $this->send();
        $this->verify($old, '000000')->assertUnprocessable();
        $this->postJson('/api/phone-verification/send', ['phone' => '09991234567'])
            ->assertStatus(429)->assertJsonPath('resend_after', 60);
        Http::assertSentCount(1);
        $this->travel(60)->seconds();
        $this->fakeSend('654321', '09991234567');
        $new = $this->postJson('/api/phone-verification/send', ['phone' => '09991234567'])->assertOk()->json('challenge_id');
        $this->assertNotSame($old, $new);
        $this->assertSame(1, DB::table('phone_verification_attempts')->value('failed_attempts'));
        $this->assertNull($this->customer->fresh()->phone);
        $this->verify($old)->assertStatus(409);
        $this->verify($new, '123456')->assertUnprocessable();
        $this->verify($new, '654321')->assertOk();
        $this->assertSame('09991234567', $this->customer->fresh()->phone);
        $this->assertNotNull($this->customer->fresh()->phone_verified_at);
        Http::assertSentCount(2);
    }

    public function test_switching_candidates_cannot_reset_account_attempt_budget(): void
    {
        $this->fakeSend();
        $old = $this->send();
        for ($i = 0; $i < 4; $i++) $this->verify($old, '000000')->assertUnprocessable();
        $this->travel(60)->seconds();
        $this->fakeSend('654321', '09991234567');
        $new = $this->postJson('/api/phone-verification/send', ['phone' => '09991234567'])->assertOk()->json('challenge_id');
        $this->assertSame(4, DB::table('phone_verification_attempts')->value('failed_attempts'));
        $this->verify($new, '000000')->assertUnprocessable();
        $this->verify($new, '654321')->assertStatus(429);
        $this->travel(60)->seconds();
        $this->postJson('/api/phone-verification/send', ['phone' => '09221234567'])->assertStatus(429);
        Http::assertSentCount(1);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
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
    public function test_failed_sends_are_sanitized_and_not_retried(array $payload, int $status, string $category, string $state = 'uncertain'): void
    {
        Http::fake(['*' => Http::response($payload, $status)]);
        $response = $this->postJson('/api/phone-verification/send', ['phone' => '09171234567']);
        $response->assertStatus(503);
        $response->assertJsonPath('challenge.status', $state)->assertJsonPath('challenge.can_verify', false)
            ->assertJsonPath('challenge.masked_destination', '0917 •••• 567')
            ->assertJsonPath('challenge.resend_after', 60);
        $this->assertSame(DB::table('phone_verification_attempts')->value('generation'), $response->json('challenge.challenge_id'));
        foreach (['test-token', '123456', 'sensitive provider'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertNull($this->customer->fresh()->phone_verified_at);
        $this->assertSame($state, DB::table('phone_verification_attempts')->value('status'));
        $this->assertCount(1, $this->smsDiagnostics);
        $diagnostic = $this->smsDiagnostics[0];
        $this->assertSame('send_otp', $diagnostic['operation']);
        $this->assertSame($category, $diagnostic['category']);
        $this->assertSame($status, $diagnostic['http_status']);
        $this->assertIsInt($diagnostic['duration_ms']);
        foreach (['test-token', '123456', 'sensitive provider', '09171234567', ':otp', 'fingerprint'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($diagnostic));
        }
        Http::assertSentCount(1);
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(429);
        Http::assertSentCount(1);
    }

    public static function badSendResponses(): array
    {
        return [
            'http error' => [['status' => 'success', 'message' => 'sensitive provider 123456'], 500, 'http_error'],
            'provider error' => [['status' => 'error', 'message' => 'sensitive provider 123456'], 200, 'provider_rejected', 'failed'],
            'documented token rejection' => [['status' => 500, 'message' => 'Invalid Token'], 200, 'provider_rejected', 'failed'],
            'documented token rejection with http error' => [['status' => 500, 'message' => 'Invalid Token'], 500, 'provider_rejected', 'failed'],
            'ambiguous server error' => [['status' => 'error', 'message' => 'sensitive provider 123456'], 500, 'http_error'],
            'http timeout' => [['status' => 'error'], 408, 'http_error'],
            'http rejection' => [['status' => 'error', 'message' => 'sensitive provider 123456'], 422, 'provider_rejected', 'failed'],
            'missing data' => [['status' => 'success'], 200, 'unexpected_response'],
            'wrong status type' => [['status' => 200], 200, 'unexpected_response'],
            'not an object' => [[], 200, 'unexpected_response'],
            'invalid expiry' => [['status' => 'success', 'data' => ['phone_number' => '09171234567', 'otp_code' => '123456', 'otp_code_confirmed' => false, 'otp_code_expires_at' => 'invalid']], 200, 'invalid_expiry'],
            'wrong destination' => [['status' => 'success', 'data' => ['phone_number' => '09991234567', 'otp_code' => '123456', 'otp_code_confirmed' => false, 'otp_code_expires_at' => '2026-10-22T12:00:00Z']], 200, 'destination_mismatch'],
            'numeric code is not documented' => [['status' => 'success', 'data' => ['phone_number' => '09171234567', 'otp_code' => 123456, 'otp_code_confirmed' => false, 'otp_code_expires_at' => '2026-10-22T12:00:00Z']], 200, 'unexpected_response'],
        ];
    }

    #[DataProvider('transportFailures')]
    public function test_transport_failure_diagnostics_are_safe_and_never_retry(int $errno, string $category, string $state): void
    {
        $previous = new \GuzzleHttp\Exception\ConnectException('test-token 123456 09171234567',
            new \GuzzleHttp\Psr7\Request('POST', 'https://www.iprogsms.com/api/v1/otp/send_otp'), null, ['errno' => $errno]);
        $calls = 0;
        Http::fake(function () use ($previous, &$calls) { $calls++; throw new ConnectionException('private request failed', 0, $previous); });
        $response = $this->postJson('/api/phone-verification/send', ['phone' => '09171234567']);
        $response->assertStatus(503)->assertJsonPath('challenge.status', $state)->assertJsonPath('challenge.can_verify', false)
            ->assertJsonPath('challenge.resend_after', 60)->assertDontSee('test-token')->assertDontSee('123456');
        $this->assertSame($state, DB::table('phone_verification_attempts')->value('status'));
        $this->assertCount(1, $this->smsDiagnostics);
        $this->assertSame($category, $this->smsDiagnostics[0]['category']);
        $this->assertSame($errno, $this->smsDiagnostics[0]['curl_errno']);
        $this->assertNull($this->smsDiagnostics[0]['http_status']);
        foreach (['test-token', '123456', '09171234567', 'private request'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($this->smsDiagnostics));
        }
        $this->postJson('/api/phone-verification/send', ['phone' => '09991234567'])->assertStatus(429);
        $this->assertSame(1, $calls);
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public static function transportFailures(): array
    {
        return [[60, 'tls_verification_failed', 'failed'], [6, 'connection_failed', 'failed'],
            [7, 'connection_failed', 'failed'], [28, 'request_timeout', 'uncertain']];
    }

    public function test_invalid_json_logs_only_its_allowlisted_response_type(): void
    {
        Http::fake(['*' => Http::response('<html>test-token 123456 09171234567</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->postJson('/api/phone-verification/send', ['phone' => '09171234567'])->assertStatus(503)
            ->assertJsonPath('challenge.status', 'uncertain');
        $this->assertSame('unexpected_response', $this->smsDiagnostics[0]['category']);
        $this->assertSame('html', $this->smsDiagnostics[0]['response_type']);
        $this->assertStringNotContainsString('test-token', json_encode($this->smsDiagnostics));
        Http::assertSentCount(1);
    }

    public function test_unconfigured_provider_logs_no_credentials_and_never_contacts_http(): void
    {
        config(['services.iprogsms.api_token' => null]);
        Http::fake();
        $this->assertNull(app(\App\Services\IprogSmsOtpService::class)->send('09171234567', (string) Str::uuid()));
        $this->assertSame('configuration_unavailable', $this->smsDiagnostics[0]['category']);
        Http::assertNothingSent();
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
        return array_map(fn ($v) => [$v], ['user_hourly_limit', 'phone_hourly_limit', 'ip_hourly_limit', 'global_hourly_limit', 'global_daily_limit', 'user_daily_limit', 'phone_daily_limit']);
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
        $this->getJson('/api/phone-verification/state')->assertUnauthorized();
        $this->postJson('/api/phone-verification/cancel', [])->assertUnauthorized();
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
