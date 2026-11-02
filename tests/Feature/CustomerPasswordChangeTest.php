<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerPasswordChangeTest extends TestCase
{
    private User $customer;
    private string $token;
    private string $deviceCookie;
    private const OLD = 'Existing meadow river password';
    private const NEW = '  violet lantern meadow river  ';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(str_repeat('A', 35).":0\r\n", 200)]);
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            foreach (['first_name', 'last_name', 'username', 'email', 'phone', 'password_hash', 'role'] as $field) $table->string($field);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedBigInteger('email_recovery_id')->nullable();
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id(); $table->morphs('tokenable'); $table->string('name');
            $table->string('token', 64)->unique(); $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
        });
        foreach (['2026_10_18_000001_add_token_session_state.php', '2026_10_25_000001_add_session_replacement.php', '2026_10_29_000003_create_remembered_devices.php'] as $migration) {
            (require base_path('database/migrations/'.$migration))->up();
        }
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('user_id')->unique(); $table->string('token_hash');
            $table->string('verified_token_hash')->nullable(); $table->unsignedInteger('verification_attempts')->default(0);
            $table->timestamp('expires_at'); $table->timestamp('last_sent_at'); $table->timestamps();
        });
        Schema::create('login_email_challenges', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('user_id'); $table->string('token_hash'); $table->string('poll_token_hash')->nullable();
            $table->boolean('remember_me')->default(false); $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable(); $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_30_000001_isolate_login_email_challenges.php'))->up();
        (require base_path('database/migrations/2026_10_30_000002_create_account_security_incidents.php'))->up();
        $this->customer = User::create(['first_name' => 'River', 'last_name' => 'Customer', 'username' => 'river',
            'email' => 'river@example.test', 'phone' => '09171234567', 'role' => 'customer',
            'password_hash' => Hash::make(self::OLD), 'email_verified_at' => now()]);
        $this->token = app(\App\Services\AccountSessionService::class)->issue($this->customer, true);
        $this->deviceCookie = app(\App\Services\RememberedDeviceService::class)->issue($this->customer, $this->token)->getValue();
    }

    protected function tearDown(): void
    {
        foreach (['cache_locks', 'cache', 'account_security_incidents', 'remembered_device_credentials', 'remembered_devices', 'personal_access_tokens', 'password_reset_requests', 'login_email_challenges', 'users'] as $table) Schema::dropIfExists($table);
        parent::tearDown();
    }

    private function change(array $overrides = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $token ??= $this->token;
        return $this->withUnencryptedCookie('bethlehem_account_session', $token)->withCredentials()
            ->withToken(explode('|', $token)[0].'|cookie')->postJson('/api/customer/password', array_replace([
                'current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW,
            ], $overrides));
    }

    public function test_success_revokes_all_credentials_and_replay_and_old_password_cannot_sign_in(): void
    {
        $extra = $this->customer->createToken('legacy')->plainTextToken;
        $extraDevice = app(\App\Services\RememberedDeviceService::class)->issue($this->customer, $extra)->getValue();
        DB::table('password_reset_requests')->insert(['user_id' => $this->customer->getKey(), 'token_hash' => 'challenge',
            'verified_token_hash' => hash('sha256', str_repeat('g', 64)), 'expires_at' => now()->addMinutes(15), 'last_sent_at' => now()]);
        $pendingOld = $this->postJson('/api/sign-in', ['identifier' => $this->customer->email, 'password' => self::OLD])->assertAccepted();
        $oldCode = Notification::sent($this->customer, ConfirmLoginNotification::class)->last()->code;
        $response = $this->change()->assertOk()->assertJson(['success' => true]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['bethlehem_account_session', 'bethlehem_remembered_device'] as $cookie) {
            $response->assertCookieExpired($cookie);
            $actual = $cookie === 'bethlehem_account_session' ? $this->accountSessionCookie($response) : $response->getCookie($cookie, false);
            $this->assertSame($cookie === 'bethlehem_account_session' ? '/' : '/api', $actual->getPath());
            $this->assertLessThan(now()->timestamp, $actual->getExpiresTime());
            $this->assertTrue($response->getCookie($cookie, false)->isHttpOnly());
        }
        $this->assertTrue(Hash::check(self::NEW, $this->customer->fresh()->password_hash));
        $this->assertFalse(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->assertDatabaseCount('remembered_devices', 2);
        $this->assertSame(0, \App\Models\RememberedDevice::whereNull('revoked_at')->count());
        Notification::assertSentToTimes($this->customer, PasswordChangedNotification::class, 1);
        $this->change()->assertUnauthorized();
        $this->change([], $extra)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->withUnencryptedCookie('bethlehem_account_session', '')
            ->withUnencryptedCookie('bethlehem_remembered_device', $this->deviceCookie)->postJson('/api/session/restore')->assertUnauthorized();
        $this->withUnencryptedCookie('bethlehem_remembered_device', $extraDevice)->postJson('/api/session/restore')->assertUnauthorized();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pendingOld->json('login_poll_token'), 'code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/password/reset', ['token' => str_repeat('g', 64), 'password' => 'RecoveryPass!678', 'password_confirmation' => 'RecoveryPass!678'])->assertUnprocessable();
        $this->postJson('/api/sign-in', ['identifier' => $this->customer->email, 'password' => self::OLD])->assertUnauthorized();
        $this->travel(61)->seconds(); // Preserve the existing login email resend cooldown.
        $pending = $this->postJson('/api/sign-in', ['identifier' => $this->customer->email, 'password' => self::NEW])->assertAccepted();
        $code = Notification::sent($this->customer, ConfirmLoginNotification::class)->last()->code;
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pending->json('login_poll_token'), 'code' => $code])->assertOk();
    }

    public function test_invalid_inputs_never_change_password_or_revoke_valid_session(): void
    {
        $cases = [
            [['current_password' => null], 'current_password'], [['current_password' => 'wrong'], 'current_password'],
            [['password' => self::OLD, 'password_confirmation' => self::OLD], 'password'],
            [['password' => 'Too short!', 'password_confirmation' => 'Too short!'], 'password'],
            [['password' => 'passwordpassword', 'password_confirmation' => 'passwordpassword'], 'password'],
            [['password_confirmation' => 'different'], 'password_confirmation'],
            [['user_id' => 999], 'user_id'],
        ];
        foreach ($cases as [$data, $field]) {
            \Illuminate\Support\Facades\Cache::flush();
            $this->change($data)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->assertNull(\App\Models\RememberedDevice::sole()->revoked_at);
        }
        Notification::assertNothingSent();
    }

    public static function cacheStores(): array
    {
        return [['array'], ['database']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cacheStores')]
    public function test_account_throttle_survives_ip_changes_and_expires(string $store): void
    {
        if ($store === 'database') (require base_path('database/migrations/0001_01_01_000001_create_cache_table.php'))->up();
        config(['cache.default' => $store]);
        for ($i = 0; $i < 5; $i++) $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])->change(['current_password' => 'wrong'])->assertUnprocessable();
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->change()->assertStatus(429);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
        $this->travel(301)->seconds();
        $this->change()->assertOk();
    }

    public function test_compromised_and_unavailable_screening_fail_closed(): void
    {
        $hash = strtoupper(sha1(self::NEW));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).':42', 200)]);
        $this->change()->assertUnprocessable()->assertJsonValidationErrors('password');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
            && $request->method() === 'GET' && $request->hasHeader('Add-Padding', 'true') && $request->body() === '');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);
        $this->change()->assertStatus(503)->assertJsonPath('code', 'password_screening_unavailable')->assertHeader('Retry-After', '60');
        $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
    }

    public function test_ip_throttle_limits_attempts_across_accounts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            // Each genuinely separate account remains below its account budget.
            $user = User::create(['first_name' => 'Customer', 'last_name' => 'Budget', 'username' => 'budget'.$i,
                'email' => 'budget'.$i.'@example.test', 'phone' => '0917000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'role' => 'customer', 'password_hash' => Hash::make(self::OLD), 'email_verified_at' => now()]);
            $token = $user->createToken('auth_token')->plainTextToken;
            $this->change(['current_password' => 'wrong'], $token)->assertUnprocessable();
        }
        $this->change()->assertStatus(429)->assertHeader('Retry-After');
        $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
    }

    public function test_bcrypt_capacity_and_unicode_boundaries(): void
    {
        foreach ([str_repeat('é', 37), str_repeat('a', 73), "null\0password value"] as $password) {
            $this->change(['password' => $password, 'password_confirmation' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $password = 'violet meadow '.str_repeat('x', 58); // Exactly 72 bytes.
        $this->change(['password' => $password, 'password_confirmation' => $password])->assertOk();
        $this->assertTrue(Hash::check($password, $this->customer->fresh()->password_hash));
    }

    public function test_unusable_accounts_and_privileged_roles_are_denied(): void
    {
        foreach ([['is_active' => false], ['is_archived' => true], ['account_deleted_at' => now()], ['email_verified_at' => null], ['email_recovery_id' => 1], ['role' => 'admin'], ['role' => 'staff']] as $changes) {
            \Illuminate\Support\Facades\Cache::flush();
            DB::table('users')->where('user_id', $this->customer->getKey())->update($changes);
            $token = $this->customer->createToken('auth_token')->plainTextToken;
            $this->change([], $token)->assertForbidden();
            DB::table('users')->where('user_id', $this->customer->getKey())->update(['is_active' => true, 'is_archived' => false, 'account_deleted_at' => null, 'email_verified_at' => now(), 'email_recovery_id' => null, 'role' => 'customer']);
            $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
        }
    }

    public function test_missing_session_and_cross_origin_requests_are_rejected(): void
    {
        $response = $this->postJson('/api/customer/password')->assertUnauthorized();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response = $this->withHeader('Origin', 'https://attacker.test')->change()->assertStatus(419);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
    }

    public function test_stale_password_verification_cannot_issue_a_new_sign_in_challenge(): void
    {
        $stale = $this->customer->fresh();
        $this->change()->assertOk();
        try {
            app(\App\Services\LoginEmailChallengeService::class)->send($stale, true);
            $this->fail('An old password verification must not create a new login challenge.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame(401, $exception->getResponse()->getStatusCode());
        }
        $this->assertDatabaseCount('login_email_challenges', 0);
    }

    public function test_pending_recovery_code_cannot_be_verified_after_password_change(): void
    {
        $this->postJson('/api/password/forgot', ['email' => $this->customer->email])->assertAccepted();
        $code = Notification::sent($this->customer, \App\Notifications\PasswordResetCodeNotification::class)->last()->code;
        $this->change()->assertOk();
        $this->withHeader('Authorization', '')->postJson('/api/password/code/verify', ['email' => $this->customer->email, 'code' => $code])->assertUnprocessable();
    }

    public function test_expired_replaced_and_locked_sessions_cannot_change_password(): void
    {
        foreach (['replaced_at', 'locked_at', 'expires_at'] as $field) {
            \Illuminate\Support\Facades\Cache::flush();
            $token = $this->customer->createToken('auth_token')->plainTextToken;
            $stored = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            $stored->forceFill([$field => now()->subSecond()])->save();
            $this->change([], $token)->assertStatus($field === 'locked_at' ? 423 : 401);
            $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
        }
    }

    public function test_targeting_another_account_cannot_modify_either_password(): void
    {
        $other = User::create(['first_name' => 'Other', 'last_name' => 'Customer', 'username' => 'other',
            'email' => 'other@example.test', 'phone' => '09171234568', 'role' => 'customer',
            'password_hash' => Hash::make('Other private password'), 'email_verified_at' => now()]);
        $this->change(['user_id' => $other->getKey()])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
        $this->assertTrue(Hash::check('Other private password', $other->fresh()->password_hash));
    }

    public function test_queue_failure_does_not_roll_back_change_or_log_exception_payload(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('secret payload'));
        \Illuminate\Support\Facades\Log::spy();
        $this->change()->assertOk();
        $this->assertTrue(Hash::check(self::NEW, $this->customer->fresh()->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('Customer password confirmation could not be queued.', ['user_id' => $this->customer->getKey()])->once();
    }

    public function test_storage_failure_rolls_back_every_credential_change_without_exposing_secrets(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'delete from "password_reset_requests"')) throw new \RuntimeException('secret request payload');
        });
        $response = $this->change()->assertStatus(500);
        $this->assertStringNotContainsString('secret request payload', $response->getContent());
        $this->assertTrue(Hash::check(self::OLD, $this->customer->fresh()->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNull(\App\Models\RememberedDevice::sole()->revoked_at);
        Notification::assertNothingSent();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('Customer password change failed.',
            ['exception_type' => \RuntimeException::class, 'user_id' => $this->customer->getKey()])->once();
    }
}
