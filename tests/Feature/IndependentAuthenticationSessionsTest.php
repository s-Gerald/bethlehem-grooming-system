<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndependentAuthenticationSessionsTest extends TestCase
{
    private array $browserCookies = [];
    private array $rememberCookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            foreach (['first_name', 'last_name', 'username', 'email', 'phone', 'password_hash', 'role'] as $field) {
                $table->string($field);
            }
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_18_000001_add_token_session_state.php'))->up();
        (require base_path('database/migrations/2026_10_25_000001_add_session_replacement.php'))->up();
        (require base_path('database/migrations/2026_10_29_000003_create_remembered_devices.php'))->up();
        Schema::create('login_email_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('poll_token_hash', 64)->nullable()->unique();
            $table->boolean('remember_me')->default(true);
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('slug');
            $table->string('service_name');
            $table->text('description')->nullable();
            foreach (['base_price', 'price_small', 'price_medium', 'price_large', 'price_extra_large', 'price_min', 'price_max'] as $field) {
                $table->decimal($field, 8, 2)->nullable();
            }
            $table->boolean('is_starting_price')->default(false);
            $table->json('starting_price_sizes')->nullable();
            $table->json('range_price_maximums')->nullable();
            $table->boolean('is_active')->default(true);
        });
        (require base_path('database/migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        foreach (['admin', 'customer'] as $index => $role) {
            User::create([
                'first_name' => $role, 'last_name' => 'Session', 'username' => $role,
                'email' => "$role@example.test", 'phone' => '0917000020'.$index,
                'password_hash' => Hash::make('SessionTest!123'), 'role' => $role,
                'is_active' => true, 'email_verified_at' => now(),
            ]);
        }
        foreach (['clinic_receptionist' => 'clinic', 'grooming_receptionist' => 'grooming'] as $subrole => $type) {
            User::create([
                'first_name' => $type, 'last_name' => 'Receptionist', 'username' => $subrole,
                'email' => "$subrole@example.test", 'phone' => '091700003'.($type === 'clinic' ? '1' : '2'),
                'password_hash' => Hash::make('SessionTest!123'), 'role' => 'staff',
                'staff_type' => $type, 'staff_subrole' => $subrole,
                'is_active' => true, 'email_verified_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['remembered_device_credentials', 'remembered_devices', 'services', 'personal_access_tokens', 'login_email_challenges', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function login(string $role, bool $remember = true): string
    {
        $this->app['auth']->forgetGuards();

        $pending = $this->postJson('/api/sign-in', [
            'identifier' => "$role@example.test", 'password' => 'SessionTest!123', 'remember' => $remember,
        ])->assertAccepted()->assertJsonMissingPath('token')->assertCookieMissing('bethlehem_account_session')
            ->assertCookieMissing('bethlehem_remembered_device');
        $user = User::where('email', "$role@example.test")->firstOrFail();
        $code = Notification::sent($user, ConfirmLoginNotification::class)->last()->code;

        $confirmed = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $pending->json('login_poll_token'), 'code' => $code,
        ])->assertOk()->assertJsonPath('user.role', $user->role);
        $this->assertSame($user->clinicPermissions(), $confirmed->json('user.clinic_permissions'));
        $this->assertSame($user->staffPermissions(), $confirmed->json('user.staff_permissions'));

        $credential = $confirmed->getCookie('bethlehem_account_session', false)->getValue();
        $cookieExpiry = $confirmed->getCookie('bethlehem_account_session', false)->getExpiresTime();
        if (! $remember) $this->assertSame(0, $cookieExpiry);
        else $this->assertGreaterThan(now()->timestamp, $cookieExpiry);
        $this->assertSame(explode('|', $credential)[0].'|cookie', $confirmed->json('token'));
        $this->browserCookies[$confirmed->json('token')] = $credential;
        $deviceCookie = $confirmed->getCookie('bethlehem_remembered_device', false);
        if ($user->role === 'customer' && $remember) {
            $this->assertNotNull($deviceCookie);
            $this->assertTrue($deviceCookie->isHttpOnly());
            $deadline = \App\Models\RememberedDevice::where('session_token_id', explode('|', $credential)[0])->sole()->expires_at;
            $this->assertSame($deadline->timestamp, $deviceCookie->getExpiresTime());
            $this->rememberCookies[$confirmed->json('token')] = $deviceCookie->getValue();
        } else $confirmed->assertCookieExpired('bethlehem_remembered_device');
        return $confirmed->json('token');
    }

    private function requestAs(string $token, string $method, string $uri, array $data = [])
    {
        // Each request is a fresh browser HTTP request, not a cached test guard.
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie('bethlehem_account_session', $this->browserCookies[$token])->withCredentials();

        return $this->json($method, $uri, $data, ['Authorization' => 'Bearer '.$token]);
    }

    public function test_unchecked_remember_me_is_nonpersistent_for_every_role(): void
    {
        foreach (['customer', 'admin', 'clinic_receptionist', 'grooming_receptionist'] as $role) {
            $token = $this->login($role, false);
            $this->requestAs($token, 'GET', '/api/session')->assertOk();
        }
    }

    public function test_shared_session_policy_gives_customers_24_hours_and_honors_explicit_token_expiry(): void
    {
        $publicId = $this->login('customer', false);
        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($this->browserCookies[$publicId]);
        $user = $token->tokenable;
        $this->travel(13)->hours();
        $token->forceFill(['last_interaction_at' => now()])->save();
        $this->assertTrue(app(\App\Services\AccountSessionService::class)->isUsable($token, $user));
        $token->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->assertFalse(app(\App\Services\AccountSessionService::class)->isUsable($token, $user));
    }

    private function restoreDevice(string $cookie, string $sessionCookie = '', array $body = [])
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', '')->withCredentials()
            ->withUnencryptedCookie('bethlehem_account_session', $sessionCookie)
            ->withUnencryptedCookie('bethlehem_remembered_device', $cookie)
            ->postJson('/api/session/restore', $body);
    }

    public function test_customer_device_is_separate_from_session_and_restores_next_day_with_rotation(): void
    {
        $old = $this->login('customer', true);
        $cookie = $this->rememberCookies[$old];
        $device = \App\Models\RememberedDevice::sole();
        $this->assertDatabaseCount('remembered_device_credentials', 1);
        $this->assertStringNotContainsString(explode('.', $cookie)[1], json_encode(DB::table('remembered_device_credentials')->get()));
        $this->travel(1)->days();
        $restored = $this->restoreDevice($cookie)->assertOk()->assertJsonPath('user.role', 'customer');
        $this->assertNotSame($old, $restored->json('token'));
        $rotated = $restored->getCookie('bethlehem_remembered_device', false);
        $this->assertNotSame($cookie, $rotated->getValue());
        $this->assertSame($device->expires_at->timestamp, $rotated->getExpiresTime());
        $this->assertStringNotContainsString($rotated->getValue(), $restored->getContent());
        $raw = $restored->getCookie('bethlehem_account_session', false)->getValue();
        $this->browserCookies[$restored->json('token')] = $raw;
        $this->requestAs($restored->json('token'), 'GET', '/api/session')->assertOk()
            ->assertJsonPath('expires_at', now()->addDay()->toIso8601String());
        $this->assertSame(1, DB::table('personal_access_tokens')->whereNull('replaced_at')->count());
        Notification::assertSentTimes(ConfirmLoginNotification::class, 1);
    }

    public function test_idle_expiry_restores_but_duplicate_requests_do_not_replace_the_restored_session(): void
    {
        $old = $this->login('customer', true);
        $this->travel(30)->minutes();
        $this->requestAs($old, 'GET', '/api/session')->assertUnauthorized();
        $first = $this->restoreDevice($this->rememberCookies[$old])->assertOk();
        $second = $this->restoreDevice($this->rememberCookies[$old])->assertOk();
        $this->assertSame($first->json('token'), $second->json('token'));
        foreach (['bethlehem_account_session', 'bethlehem_remembered_device'] as $name) {
            $this->assertSame($first->getCookie($name, false)->getValue(), $second->getCookie($name, false)->getValue());
        }
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('remembered_device_credentials', 2);
    }

    public function test_consumed_credential_replay_after_grace_revokes_device_and_its_session(): void
    {
        $old = $this->login('customer', true);
        $this->travel(31)->minutes();
        $this->restoreDevice($this->rememberCookies[$old])->assertOk();
        $this->travel(61)->seconds();
        $this->restoreDevice($this->rememberCookies[$old])->assertUnauthorized();
        $this->assertNotNull(\App\Models\RememberedDevice::sole()->revoked_at);
        $this->assertSame(0, DB::table('personal_access_tokens')->whereNull('replaced_at')->count());
    }

    public function test_remembered_inactivity_expires_at_seven_days(): void
    {
        $old = $this->login('customer', true);
        $this->travel(7)->days();
        $this->restoreDevice($this->rememberCookies[$old])->assertUnauthorized()->assertJsonPath('code', 'remembered_session_invalid');
        $this->assertNotNull(\App\Models\RememberedDevice::sole()->revoked_at);
    }

    public function test_absolute_fourteen_day_deadline_is_never_extended_by_daily_restoration(): void
    {
        $old = $this->login('customer', true);
        $cookie = $this->rememberCookies[$old];
        $deadline = \App\Models\RememberedDevice::sole()->expires_at;
        for ($day = 1; $day < 14; $day++) {
            $this->travel(1)->days();
            $response = $this->restoreDevice($cookie)->assertOk();
            $cookie = $response->getCookie('bethlehem_remembered_device', false)->getValue();
            $this->assertTrue($deadline->equalTo(\App\Models\RememberedDevice::sole()->expires_at));
        }
        $this->travel(1)->days();
        $this->restoreDevice($cookie)->assertUnauthorized();
    }

    public function test_valid_active_cookie_recovery_does_not_create_or_replace_a_session(): void
    {
        $old = $this->login('customer', true);
        $this->restoreDevice($this->rememberCookies[$old], $this->browserCookies[$old])->assertOk()->assertJsonPath('token', $old);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('remembered_device_credentials', 1);
    }

    public function test_unchecked_customer_can_recover_its_existing_browser_session_without_becoming_remembered(): void
    {
        $old = $this->login('customer', false);
        $this->restoreDevice('', $this->browserCookies[$old])->assertOk()->assertJsonPath('token', $old)
            ->assertJsonPath('remember_me', false)->assertCookieMissing('bethlehem_remembered_device');
        $this->assertDatabaseCount('remembered_devices', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->travel(30)->minutes();
        $this->restoreDevice('', $this->browserCookies[$old])->assertUnauthorized();
    }

    public static function privilegedRememberChoices(): array
    {
        return [['admin', false], ['admin', true], ['clinic_receptionist', false], ['clinic_receptionist', true]];
    }

    #[DataProvider('privilegedRememberChoices')]
    public function test_privileged_checkbox_never_changes_lock_or_absolute_expiration(string $role, bool $remember): void
    {
        $old = $this->login($role, $remember);
        $this->travel(30)->minutes();
        $this->requestAs($old, 'GET', '/api/me')->assertStatus(423);
        $this->restoreDevice('', $this->browserCookies[$old])->assertUnauthorized();
        $this->requestAs($old, 'POST', '/api/session/unlock', ['password' => 'SessionTest!123'])->assertOk();
        $this->travel(11)->hours();
        $this->travel(30)->minutes();
        $this->requestAs($old, 'GET', '/api/session')->assertUnauthorized();
        $this->assertDatabaseCount('remembered_devices', 0);
    }

    public function test_explicit_logout_revokes_device_and_expires_both_cookies_even_after_session_expiry(): void
    {
        $old = $this->login('customer', true);
        $this->travel(1)->days();
        $this->withUnencryptedCookie('bethlehem_remembered_device', $this->rememberCookies[$old]);
        $this->requestAs($old, 'POST', '/api/logout')->assertOk()
            ->assertCookieExpired('bethlehem_account_session')->assertCookieExpired('bethlehem_remembered_device');
        $this->restoreDevice($this->rememberCookies[$old])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_can_revoke_device_when_active_token_and_cookie_are_already_gone(): void
    {
        $old = $this->login('customer', true);
        DB::table('personal_access_tokens')->delete();
        $this->withHeader('Authorization', 'Bearer '.$old)->withCredentials()
            ->withUnencryptedCookie('bethlehem_account_session', '')
            ->withUnencryptedCookie('bethlehem_remembered_device', $this->rememberCookies[$old])
            ->postJson('/api/logout')->assertOk()->assertCookieExpired('bethlehem_remembered_device');
        $this->restoreDevice($this->rememberCookies[$old])->assertUnauthorized();
    }

    public function test_logout_started_during_restoration_can_revoke_the_successor_on_the_same_device(): void
    {
        $old = $this->login('customer', true);
        $this->travel(31)->minutes();
        $restored = $this->restoreDevice($this->rememberCookies[$old])->assertOk();
        $rotated = $restored->getCookie('bethlehem_remembered_device', false)->getValue();
        $this->withHeader('Authorization', 'Bearer '.$old)->withCredentials()
            ->withUnencryptedCookie('bethlehem_account_session', $restored->getCookie('bethlehem_account_session', false)->getValue())
            ->withUnencryptedCookie('bethlehem_remembered_device', $rotated)->postJson('/api/logout')->assertOk();
        $this->restoreDevice($rotated)->assertUnauthorized();
    }

    public function test_obsolete_logout_cannot_remove_a_new_full_login_cookie_or_device(): void
    {
        $old = $this->login('customer', true);
        $this->travel(61)->seconds();
        $new = $this->login('customer', true);
        $this->withHeader('Authorization', 'Bearer '.$old)->withCredentials()
            ->withUnencryptedCookie('bethlehem_account_session', $this->browserCookies[$new])
            ->withUnencryptedCookie('bethlehem_remembered_device', $this->rememberCookies[$new])
            ->postJson('/api/logout')->assertUnauthorized()->assertCookieMissing('bethlehem_account_session')
            ->assertCookieMissing('bethlehem_remembered_device');
        $this->requestAs($new, 'GET', '/api/me')->assertOk();
    }

    public function test_remembered_migration_round_trip_preserves_existing_accounts_and_sessions(): void
    {
        $token = $this->login('customer', false);
        $users = DB::table('users')->get()->toArray();
        $sessions = DB::table('personal_access_tokens')->get()->toArray();
        $migration = require base_path('database/migrations/2026_10_29_000003_create_remembered_devices.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($users, DB::table('users')->get()->toArray());
        $this->assertEquals($sessions, DB::table('personal_access_tokens')->get()->toArray());
        $this->requestAs($token, 'GET', '/api/me')->assertOk();
    }

    public function test_remembered_activity_is_bounded_and_never_revives_an_expired_credential(): void
    {
        $token = $this->login('customer', true);
        $device = \App\Models\RememberedDevice::sole();
        $this->travel(20)->minutes();
        $this->requestAs($token, 'POST', '/api/session/activity')->assertOk();
        $this->assertTrue($device->last_used_at->equalTo($device->fresh()->last_used_at));
        for ($step = 0; $step < 3; $step++) {
            $this->travel(20)->minutes();
            $this->requestAs($token, 'POST', '/api/session/activity')->assertOk();
        }
        $this->assertTrue($device->fresh()->last_used_at->greaterThan($device->last_used_at));
        $device->update(['last_used_at' => now()->subDays(7)]);
        DB::table('personal_access_tokens')->update(['last_interaction_at' => now()->subDays(7)]);
        $this->requestAs($token, 'POST', '/api/session/activity')->assertUnauthorized();
        $this->restoreDevice($this->rememberCookies[$token])->assertUnauthorized();
    }

    public function test_sparse_interactions_do_not_leave_the_device_activity_checkpoint_more_than_an_hour_behind(): void
    {
        $token = $this->login('customer', true);
        foreach ([20, 20, 19, 29, 22, 20, 20] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->requestAs($token, 'POST', '/api/session/activity')->assertOk();
        }
        $this->assertTrue(\App\Models\RememberedDevice::sole()->last_used_at->gte(now()->subHour()));
    }

    public static function inactivityBoundary(): array
    {
        return [['idle', -1], ['idle', 0], ['sanctum', -1], ['prune', -1]];
    }

    #[DataProvider('inactivityBoundary')]
    public function test_seven_day_device_window_uses_the_actual_last_interaction_after_session_deletion(string $expiration, int $offset): void
    {
        $token = $this->login('customer', true);
        $this->travel(20)->minutes();
        $this->requestAs($token, 'POST', '/api/session/activity')->assertOk();
        $interaction = \Illuminate\Support\Carbon::parse(DB::table('personal_access_tokens')->value('last_interaction_at'));
        if ($expiration === 'prune') {
            $this->travel(49)->hours();
            // Laravel intentionally disables Symfony console events in unit tests.
            $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
            $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();
        } else {
            $this->travel($expiration === 'idle' ? 1 : 25)->hours();
            $this->requestAs($token, 'GET', '/api/session')->assertUnauthorized();
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(\App\Models\RememberedDevice::sole()->last_used_at->equalTo($interaction));
        $this->travelTo($interaction->copy()->addDays(7)->addSeconds($offset));
        $response = $this->restoreDevice($this->rememberCookies[$token]);
        if ($offset < 0) $response->assertOk();
        else $response->assertUnauthorized();
    }

    public function test_restoration_reads_actual_activity_from_an_expired_session_that_has_not_been_pruned(): void
    {
        $token = $this->login('customer', true);
        $this->travel(20)->minutes();
        $this->requestAs($token, 'POST', '/api/session/activity')->assertOk();
        $interaction = \Illuminate\Support\Carbon::parse(DB::table('personal_access_tokens')->value('last_interaction_at'));
        $this->travelTo($interaction->copy()->addDays(7)->subSecond());
        $this->restoreDevice($this->rememberCookies[$token])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_new_device_full_login_revokes_previous_device_without_a_restoration_loop(): void
    {
        $old = $this->login('customer', true);
        $this->travel(61)->seconds();
        $new = $this->login('customer', true);
        $this->restoreDevice($this->rememberCookies[$old])->assertUnauthorized();
        $this->restoreDevice($this->rememberCookies[$new], $this->browserCookies[$new])->assertOk()->assertJsonPath('token', $new);
        $this->requestAs($new, 'GET', '/api/me')->assertOk();
    }

    public function test_password_change_and_deactivation_permanently_revoke_remembered_devices(): void
    {
        $old = $this->login('customer', true);
        $user = User::where('role', 'customer')->firstOrFail();
        $user->update(['password_hash' => Hash::make('Changed!123')]);
        $this->restoreDevice($this->rememberCookies[$old])->assertUnauthorized();
        $user->update(['password_hash' => Hash::make('SessionTest!123')]);
        $this->travel(61)->seconds();
        $new = $this->login('customer', true);
        $user->update(['is_active' => false]);
        $user->update(['is_active' => true]);
        $this->restoreDevice($this->rememberCookies[$new])->assertUnauthorized();
    }

    public function test_restore_rejects_invalid_credentials_and_origins_without_exposing_accounts(): void
    {
        $old = $this->login('customer', true);
        $wrong = explode('.', $this->rememberCookies[$old])[0].'.'.str_repeat('0', 64);
        $this->restoreDevice($wrong)->assertUnauthorized()->assertJsonPath('code', 'remembered_session_invalid');
        $this->assertNull(\App\Models\RememberedDevice::sole()->revoked_at);
        $this->restoreDevice($this->rememberCookies[$old], '', ['account_id' => 99999])->assertUnauthorized();
        $this->withHeader('Origin', 'https://unrelated.example');
        $this->restoreDevice($this->rememberCookies[$old])->assertStatus(419);
        $this->withHeader('Origin', '')->withHeader('X-Requested-With', '');
        $this->restoreDevice($this->rememberCookies[$old])->assertStatus(419);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->restoreDevice('invalid')->assertUnauthorized()->assertJsonMissingPath('user');
    }

    public function test_restore_limiter_is_independent_of_password_and_does_not_share_an_empty_cookie_bucket(): void
    {
        for ($attempt = 0; $attempt < 15; $attempt++) $this->restoreDevice('')->assertUnauthorized();
        $this->restoreDevice('')->assertTooManyRequests();
        $token = $this->login('customer', false);
        $this->restoreDevice('', $this->browserCookies[$token])->assertOk();
    }

    public function test_production_cookie_attributes_are_host_only_secure_and_api_scoped(): void
    {
        config(['app.url' => 'https://clinic.example']);
        $old = $this->login('customer', true);
        $response = $this->restoreDevice($this->rememberCookies[$old], $this->browserCookies[$old])->assertOk();
        $cookie = $response->getCookie('bethlehem_account_session', false);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/api', $cookie->getPath());
        $this->travel(31)->minutes();
        $remembered = $this->restoreDevice($this->rememberCookies[$old])->assertOk()->getCookie('bethlehem_remembered_device', false);
        $this->assertTrue($remembered->isSecure());
        $this->assertTrue($remembered->isHttpOnly());
        $this->assertNull($remembered->getDomain());
        $this->assertSame('/api', $remembered->getPath());
        $this->assertSame('lax', $remembered->getSameSite());
    }

    public static function loginOrders(): array
    {
        return [['admin', 'customer'], ['customer', 'admin'],
            ['clinic_receptionist', 'grooming_receptionist'], ['grooming_receptionist', 'clinic_receptionist']];
    }

    #[DataProvider('loginOrders')]
    public function test_both_login_orders_keep_both_tokens_and_catalogue_access(string $first, string $second): void
    {
        $tokens = [$first => $this->login($first), $second => $this->login($second)];
        $this->assertNotSame($tokens[$first], $tokens[$second]);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        for ($round = 0; $round < 3; $round++) {
            foreach ($tokens as $role => $token) {
                $user = User::where('email', "$role@example.test")->firstOrFail();
                $this->requestAs($token, 'GET', '/api/me')->assertOk()
                    ->assertJsonPath('user.user_id', $user->user_id)
                    ->assertJsonPath('user.role', $user->role)
                    ->assertJsonPath('user.staff_subrole', $user->staff_subrole);
                $this->requestAs($token, 'GET', '/api/grooming/services')->assertOk();
            }
        }
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->requestAs($tokens[$second], 'POST', '/api/logout')->assertOk();
        $this->requestAs($tokens[$second], 'GET', '/api/me')->assertUnauthorized();
        $this->requestAs($tokens[$first], 'GET', '/api/me')->assertOk()
            ->assertJsonPath('user.user_id', User::where('email', "$first@example.test")->value('user_id'));
        $this->requestAs($tokens[$first], 'GET', '/api/grooming/services')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_receptionist_permissions_are_equivalent_without_changing_staff_identity(): void
    {
        $clinic = User::where('staff_subrole', 'clinic_receptionist')->firstOrFail();
        $grooming = User::where('staff_subrole', 'grooming_receptionist')->firstOrFail();
        $this->assertSame($clinic->clinicPermissions(), $grooming->clinicPermissions());
        $this->assertSame($clinic->staffPermissions(), $grooming->staffPermissions());
        foreach ([$clinic, $grooming] as $user) {
            $this->assertTrue($user->clinicPermissions()['access']);
            $this->assertTrue($user->staffPermissions()['grooming_services_view']);
            $this->assertFalse($user->clinicPermissions()['clinical']);
            $this->assertFalse($user->clinicPermissions()['billing_edit']);
            $this->assertFalse($user->staffPermissions()['services_manage']);
        }
        $this->assertSame('grooming', $grooming->fresh()->staff_type);
    }

    public function test_invalid_receptionist_type_pairs_do_not_gain_operational_access(): void
    {
        foreach ([['clinic', 'grooming_receptionist'], ['grooming', 'clinic_receptionist'],
            ['grooming', null], ['clinic', 'unknown']] as [$type, $subrole]) {
            $user = new User(['role' => 'staff', 'staff_type' => $type, 'staff_subrole' => $subrole]);
            $this->assertFalse($user->clinicPermissions()['access']);
            $this->assertFalse($user->staffPermissions()['grooming']);
            $this->assertFalse($user->staffPermissions()['grooming_services_view']);
        }
    }

    #[DataProvider('receptionistOrders')]
    public function test_receptionist_lock_unlock_and_expiry_affect_only_the_requesting_token(string $first, string $second): void
    {
        $normal = $this->login($first);
        $this->travel(20)->minutes();
        $private = $this->login($second);
        $this->travel(10)->minutes();
        $this->requestAs($normal, 'GET', '/api/me')->assertStatus(423);
        $this->requestAs($private, 'GET', '/api/me')->assertOk()->assertJsonPath('user.staff_subrole', $second);
        $this->requestAs($normal, 'POST', '/api/session/unlock', ['password' => 'wrong'])->assertUnprocessable();
        $this->requestAs($normal, 'POST', '/api/session/unlock', ['password' => 'SessionTest!123'])->assertOk()
            ->assertJsonPath('locked', false);
        $this->requestAs($normal, 'GET', '/api/me')->assertOk()->assertJsonPath('user.staff_subrole', $first);
        $this->travel(11)->hours();
        $this->travel(30)->minutes();
        $this->requestAs($normal, 'GET', '/api/me')->assertUnauthorized();
        $this->requestAs($private, 'POST', '/api/session/unlock', ['password' => 'SessionTest!123'])->assertOk();
        $this->requestAs($private, 'GET', '/api/me')->assertOk()->assertJsonPath('user.staff_subrole', $second);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public static function receptionistOrders(): array
    {
        return [['clinic_receptionist', 'grooming_receptionist'], ['grooming_receptionist', 'clinic_receptionist']];
    }

    public function test_logout_only_revokes_the_requesting_token_even_for_the_same_account(): void
    {
        $normal = $this->login('admin');
        $this->travel(61)->seconds();
        $private = $this->login('admin');
        $this->requestAs($normal, 'POST', '/api/logout')->assertUnauthorized()->assertJsonPath('code', 'session_replaced');
        $this->requestAs($normal, 'GET', '/api/me')->assertUnauthorized();
        $this->requestAs($private, 'GET', '/api/me')->assertOk()->assertJsonPath('user.role', 'admin');
        $this->requestAs($private, 'POST', '/api/logout')->assertOk();
        $this->requestAs($private, 'GET', '/api/me')->assertUnauthorized();
    }

    public static function accountRoles(): array
    {
        return array_map(fn ($role) => [$role], ['admin', 'customer', 'clinic_receptionist', 'grooming_receptionist', 'veterinarian']);
    }

    #[DataProvider('accountRoles')]
    public function test_only_completed_authentication_replaces_the_old_session(string $role): void
    {
        if ($role === 'veterinarian') {
            User::create(['first_name' => 'Vet', 'last_name' => 'Test', 'username' => $role,
                'email' => "$role@example.test", 'phone' => '0917000040', 'role' => 'staff',
                'staff_type' => 'clinic', 'staff_subrole' => 'veterinarian',
                'password_hash' => Hash::make('SessionTest!123'), 'email_verified_at' => now()]);
        }
        $old = $this->login($role);
        $this->requestAs($old, 'GET', '/api/me')->assertOk();
        $this->postJson('/api/sign-in', ['identifier' => "$role@example.test", 'password' => 'wrong'])->assertUnauthorized();
        $this->requestAs($old, 'GET', '/api/me')->assertOk();
        $this->travel(61)->seconds();
        $pending = $this->postJson('/api/sign-in', ['identifier' => "$role@example.test", 'password' => 'SessionTest!123'])->assertAccepted();
        $this->requestAs($old, 'GET', '/api/me')->assertOk();
        $user = User::where('email', "$role@example.test")->firstOrFail();
        $code = Notification::sent($user, ConfirmLoginNotification::class)->last()->code;
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pending->json('login_poll_token'), 'code' => $wrong])->assertUnprocessable();
        $this->requestAs($old, 'GET', '/api/me')->assertOk();
        $this->travel(15)->minutes();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pending->json('login_poll_token'), 'code' => $code])->assertUnprocessable()->assertJsonPath('expired', true);
        $this->requestAs($old, 'GET', '/api/me')->assertOk();
        $new = $this->login($role);
        $this->assertNotSame($old, $new);
        foreach (['/api/me', '/api/session', '/api/booking/history'] as $uri) {
            $this->requestAs($old, 'GET', $uri)->assertUnauthorized()->assertJsonPath('code', 'session_replaced');
        }
        $this->requestAs($old, 'POST', '/api/pets', [])->assertUnauthorized()->assertJsonPath('code', 'session_replaced');
        $this->requestAs($new, 'GET', '/api/me')->assertOk();
    }

    public function test_availability_html_navigation_authenticates_the_http_only_cookie(): void
    {
        $token = $this->login('admin');
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie('bethlehem_account_session', $this->browserCookies[$token])->withCredentials();
        $this->get('/api/admin/availability/page', ['Accept' => 'text/html'])->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $customer = $this->login('customer');
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie('bethlehem_account_session', $this->browserCookies[$customer]);
        $this->get('/api/admin/availability/page', ['Accept' => 'text/html'])->assertForbidden();
    }

    public function test_guest_html_availability_navigation_returns_unauthorized_without_a_login_route(): void
    {
        $this->get('/api/admin/availability/page', ['Accept' => 'text/html'])->assertUnauthorized();
    }

    public function test_cookie_transport_requires_both_cookie_and_bound_identifier_and_rejects_csrf(): void
    {
        $publicId = $this->login('customer');
        $cookie = $this->browserCookies[$publicId];
        $this->withToken($publicId)->getJson('/api/me')->assertUnauthorized();
        $this->withUnencryptedCookie('bethlehem_account_session', $cookie)->withCredentials();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken('999999|cookie')->postJson('/api/pets')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($publicId)->postJson('/api/logout', [], ['Origin' => 'https://unrelated.example'])->assertStatus(419);
        $this->withHeader('X-Requested-With', '')->postJson('/api/logout')->assertStatus(419);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->requestAs($publicId, 'GET', '/api/me')->assertOk();
    }

    public function test_https_cookie_upgrade_does_not_issue_another_token_or_extend_deadlines(): void
    {
        config(['app.url' => 'https://clinic.example']);
        $publicId = $this->login('customer', false);
        $raw = $this->browserCookies[$publicId];
        $before = DB::table('personal_access_tokens')->sole();
        $this->travel(10)->minutes();
        $this->app['auth']->forgetGuards();
        $status = $this->getJson('/api/session', ['Authorization' => 'Bearer '.$raw])->assertOk();
        $cookie = $status->getCookie('bethlehem_account_session', false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/api', $cookie->getPath());
        $this->assertSame(0, $cookie->getExpiresTime());
        $this->assertNull($cookie->getDomain());
        $this->assertStringNotContainsString($raw, $status->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $after = DB::table('personal_access_tokens')->sole();
        $this->assertSame($before->created_at, $after->created_at);
        $this->assertSame($before->last_interaction_at, $after->last_interaction_at);
        $this->assertNull($after->replaced_at);
    }

    public function test_migration_preserves_rows_and_newest_existing_session_for_each_account(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $customer = User::where('role', 'customer')->firstOrFail();
        $old = $admin->createToken('admin_token')->accessToken;
        $new = $admin->createToken('admin_token')->accessToken;
        $other = $customer->createToken('auth_token')->accessToken;
        $migration = require base_path('database/migrations/2026_10_25_000001_add_session_replacement.php');
        $migration->down();
        $before = \Illuminate\Support\Facades\DB::table('personal_access_tokens')->orderBy('id')->get()->toArray();
        $migration->up();
        $this->assertNotNull($old->fresh()->replaced_at);
        $this->assertNull($new->fresh()->replaced_at);
        $this->assertNull($other->fresh()->replaced_at);
        $migration->down();
        $this->assertEquals($before, \Illuminate\Support\Facades\DB::table('personal_access_tokens')->orderBy('id')->get()->toArray());
    }

    public static function overlappingAuthentication(): array
    {
        return [['otp'], ['restore']];
    }

    #[DataProvider('overlappingAuthentication')]
    public function test_overlapping_authentication_creates_exactly_one_session(string $mode): void
    {
        if ($mode === 'otp') {
            $pending = $this->postJson('/api/sign-in', ['identifier' => 'admin@example.test', 'password' => 'SessionTest!123'])->assertAccepted();
            $code = Notification::sent(User::where('role', 'admin')->firstOrFail(), ConfirmLoginNotification::class)->last()->code;
            $payload = ['path' => '/api/email/login/confirm', 'body' => ['poll_token' => $pending->json('login_poll_token'), 'code' => $code], 'cookies' => []];
        } else {
            $old = $this->login('customer', true);
            DB::table('personal_access_tokens')->update(['created_at' => now()->subHours(25)]);
            $payload = ['path' => '/api/session/restore', 'body' => [],
                'cookies' => ['bethlehem_remembered_device' => $this->rememberCookies[$old]]];
        }
        $payload = json_encode($payload, JSON_THROW_ON_ERROR);
        $directory = sys_get_temp_dir().'/bethlehem-session-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/sessions.sqlite';
        $first = $second = $connection = null;
        try {
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                [$script, $database, $role, $payload] = $argv;
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-session-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'sessions.sqlite' || !is_file($database)) {
                    throw new RuntimeException('Only the disposable session test database is allowed.');
                }
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
                Illuminate\Support\Facades\DB::purge('sqlite');
                $connection = Illuminate\Support\Facades\DB::connection();
                if (realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) {
                    throw new RuntimeException('Unexpected database.');
                }
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Session test barrier timed out.');
                };
                $busy = false;
                $connection->beforeStartingTransaction(function () use ($role, $directory, $database, $wait, &$busy) {
                    if ($role !== 'second') return;
                    $wait($directory.'/first-lock');
                    $probe = new PDO('sqlite:'.$database);
                    $probe->exec('PRAGMA busy_timeout=0');
                    try { $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK'); }
                    catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 5) throw $e; $busy = true; }
                    touch($directory.'/second-attempt');
                });
                $app['events']->listen(Illuminate\Database\Events\TransactionBeginning::class,
                    function () use ($role, $directory, $wait) {
                        if ($role !== 'first') return;
                        touch($directory.'/first-lock');
                        $wait($directory.'/second-attempt');
                    });
                $input = json_decode($payload, true);
                $request = Illuminate\Http\Request::create($input['path'], 'POST', $input['body'], $input['cookies'],
                    server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
                echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'busy' => $busy,
                    'public_id' => json_decode($response->getContent(), true)['token'] ?? null,
                    'cookies' => array_map(fn ($cookie) => hash('sha256', $cookie->getValue()), $response->headers->getCookies())]);
                PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            $first = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'first', $payload], base_path(), $environment);
            $second = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'second', $payload], base_path(), $environment);
            $first->setTimeout(15); $second->setTimeout(15);
            $first->start(); $second->start();
            $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($one['pid'], $two['pid']);
            $this->assertTrue($two['busy'], 'The second verified login must overlap the first transaction.');
            $this->assertSame(200, $one['status']);
            if ($mode === 'otp') {
                $this->assertSame(422, $two['status']);
                $this->assertNull($two['public_id']);
            } else {
                $this->assertSame(200, $two['status']);
                $this->assertSame($one['public_id'], $two['public_id']);
                $this->assertSame($one['cookies'], $two['cookies']);
            }
            $connection = new \PDO('sqlite:'.$database);
            $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn());
            $active = $connection->query('SELECT id FROM personal_access_tokens WHERE replaced_at IS NULL')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame([explode('|', $one['public_id'])[0]], array_map('strval', $active));
        } finally {
            $first?->stop(); $second?->stop(); $connection = null;
            foreach (['sessions.sqlite', 'sessions.sqlite-journal', 'sessions.sqlite-wal', 'sessions.sqlite-shm', 'first-lock', 'second-attempt'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }

    public function test_overlapping_password_failures_cannot_skip_or_extend_the_fifth_attempt_cooldown(): void
    {
        $migration = require base_path('database/migrations/0001_01_01_000001_create_cache_table.php');
        $migration->up();
        config(['cache.default' => 'database']);
        $payload = ['identifier' => 'customer@example.test', 'password' => 'wrong'];
        foreach (range(1, 4) as $attempt) $this->postJson('/api/sign-in', $payload)->assertUnauthorized();
        $directory = sys_get_temp_dir().'/bethlehem-signin-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/signin.sqlite';
        $first = $second = null;
        try {
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                [$script, $database, $role] = $argv;
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-signin-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'signin.sqlite' || !is_file($database)) {
                    throw new RuntimeException('Only the disposable sign-in test database is allowed.');
                }
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'cache.default' => 'database']);
                Illuminate\Support\Facades\DB::purge('sqlite');
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Sign-in overlap barrier timed out.');
                };
                if ($role === 'first') {
                    Illuminate\Support\Facades\DB::listen(function ($query) use ($directory, $wait) {
                        if (!str_contains($query->sql, 'from "users"')) return;
                        touch($directory.'/first-check'); // Pair cache lock is held before the user lookup.
                        $wait($directory.'/second-done');
                    });
                } else $wait($directory.'/first-check');
                $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
                $request = fn () => Illuminate\Http\Request::create('/api/sign-in', 'POST',
                    ['identifier' => 'customer@example.test', 'password' => 'wrong'],
                    server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $response = $kernel->handle($request());
                $data = json_decode($response->getContent(), true);
                touch($directory.'/second-done');
                echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'code' => $data['code'] ?? null,
                    'retry_after' => $data['retry_after'] ?? null]);
                PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'database',
                'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            $first = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'first'], base_path(), $environment);
            $second = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'second'], base_path(), $environment);
            $first->setTimeout(15); $second->setTimeout(15);
            $first->start(); $second->start(); $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput().' First: '.$first->getOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($one['pid'], $two['pid']);
            $this->assertSame('sign_in_password_cooldown', $one['code']);
            $this->assertSame(429, $one['status']);
            $this->assertSame(60, $one['retry_after']);
            $this->assertSame('sign_in_busy', $two['code'], 'The concurrent request cannot race the counter update.');
            $this->assertSame(429, $two['status']);
        } finally {
            $first?->stop(); $second?->stop();
            foreach (['signin.sqlite', 'signin.sqlite-journal', 'signin.sqlite-wal', 'signin.sqlite-shm', 'first-check', 'second-done'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
            $migration->down();
        }
    }

    public function test_expired_privileged_session_requires_the_existing_full_email_otp_login(): void
    {
        $token = $this->login('admin');
        $this->travel(12)->hours();
        $this->requestAs($token, 'GET', '/api/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        // login() asserts password sign-in issues no token until email confirmation.
        $replacement = $this->login('admin');
        $this->assertNotSame($token, $replacement);
        $this->requestAs($replacement, 'GET', '/api/me')->assertOk();
        Notification::assertSentTimes(ConfirmLoginNotification::class, 2);
    }

    public function test_pricing_reads_and_updates_do_not_invalidate_either_session(): void
    {
        $admin = $this->login('admin');
        $customer = $this->login('customer');
        $service = Service::create([
            'slug' => 'ear_cleaning', 'service_name' => 'Ear Cleaning', 'kind' => 'ala_carte', 'pet_type' => 'all',
            'base_price' => 150, 'price_min' => 150, 'is_starting_price' => true,
            'is_active' => true,
        ]);
        $url = "/api/admin/grooming/services/{$service->service_id}/pricing";
        $this->requestAs($customer, 'PATCH', $url, ['pricing_type' => 'fixed', 'amount' => '200'])->assertForbidden();
        $this->requestAs($admin, 'PATCH', $url, ['pricing_type' => 'starting_at', 'amount' => '175'])->assertOk();

        foreach ([$admin => 'admin', $customer => 'customer'] as $token => $role) {
            $this->requestAs($token, 'GET', '/api/grooming/services')->assertOk()
                ->assertJsonPath('data.0.priceOptions.0.minAmount', 175)
                ->assertJsonPath('data.0.priceOptions.0.pricingType', 'starting_at');
            $this->requestAs($token, 'GET', '/api/me')->assertOk()->assertJsonPath('user.role', $role);
        }
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }
}
