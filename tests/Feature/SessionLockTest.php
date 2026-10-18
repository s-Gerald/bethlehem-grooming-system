<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(now()->startOfSecond());
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            foreach (['first_name', 'last_name', 'username', 'email', 'phone', 'password_hash', 'role'] as $field) {
                $table->string($field);
            }
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
        });
        (require base_path('database/migrations/2026_04_01_145449_create_personal_access_tokens_table.php'))->up();
        (require base_path('database/migrations/2026_10_18_000001_add_token_session_state.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        $this->travelBack();
        parent::tearDown();
    }

    private function token(string $role = 'admin'): string
    {
        $id = User::count() + 1;
        $user = User::create([
            'first_name' => 'Session', 'last_name' => 'Test', 'username' => "user$id",
            'email' => "user$id@example.test", 'phone' => "0917000000$id",
            'password_hash' => Hash::make('SessionTest!123'), 'role' => $role,
            'email_verified_at' => now(), 'is_active' => true,
        ]);

        return $user->createToken($role === 'customer' ? 'auth_token' : 'admin_token')->plainTextToken;
    }

    private function api(string $token, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api'.$uri, $data, ['Authorization' => 'Bearer '.$token]);
    }

    public function test_privileged_idle_sessions_lock_without_revocation_and_password_unlock_keeps_token(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $token = $this->token($role);
            $created = DB::table('personal_access_tokens')->latest('id')->value('created_at');
            $this->travel(29)->minutes();
            $this->api($token, 'GET', '/me')->assertOk();
            $this->travel(1)->minutes();
            $this->api($token, 'GET', '/me')->assertStatus(423)->assertJsonPath('code', 'session_locked');
            $this->api($token, 'POST', '/pets', [])->assertStatus(423);
            $this->api($token, 'GET', '/session')->assertOk()->assertJsonPath('locked', true);
            $this->api($token, 'POST', '/session/activity', ['at' => now()->addYear()->timestamp])->assertStatus(423);
            $this->api($token, 'POST', '/session/unlock', ['password' => 'wrong'])->assertUnprocessable()
                ->assertJsonPath('message', 'Incorrect password.');
            $this->api($token, 'GET', '/me')->assertStatus(423);
            $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])->assertOk()
                ->assertJsonPath('locked', false);
            $this->api($token, 'GET', '/me')->assertOk()->assertJsonPath('user.role', $role);
            $this->assertSame($created, DB::table('personal_access_tokens')->latest('id')->value('created_at'));
            Notification::assertNothingSent();
        }
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_session_revision_tracks_navigation_identity_without_exposing_profile_data(): void
    {
        $token = $this->token('staff');
        $identity = $this->api($token, 'GET', '/me')->assertOk()->json();
        $revision = $identity['sidebar_revision'] ?? null;
        $this->assertIsString($revision);
        $session = $this->api($token, 'GET', '/session')->assertOk()
            ->assertJsonPath('sidebar_revision', $revision)->assertJsonPath('role', 'staff')->json();
        $this->assertArrayNotHasKey('user', $session);
        DB::table('users')->update(['first_name' => 'Updated']);
        $changed = $this->api($token, 'GET', '/session')->assertOk()->json('sidebar_revision');
        $this->assertNotSame($revision, $changed);
        DB::table('users')->update(['role' => 'admin']);
        $this->api($token, 'GET', '/session')->assertOk()->assertJsonPath('role', 'admin');
        $this->assertNotSame($changed, $this->api($token, 'GET', '/me')->json('sidebar_revision'));
    }

    public function test_status_and_background_requests_never_extend_idle_time_but_explicit_activity_does(): void
    {
        $token = $this->token();
        $this->travel(20)->minutes();
        $this->api($token, 'GET', '/session')->assertOk();
        $this->api($token, 'POST', '/session/activity', ['at' => now()->addYear()->timestamp])->assertOk();
        $this->travel(29)->minutes();
        $this->api($token, 'GET', '/me')->assertOk();
        $this->api($token, 'GET', '/session')->assertOk()->assertJsonPath('locked', false);
        $this->travel(1)->minutes();
        $this->api($token, 'GET', '/me')->assertStatus(423);
        $this->api($token, 'POST', '/session/activity')->assertStatus(423);
    }

    public function test_privileged_absolute_limit_revokes_even_after_unlock_and_activity(): void
    {
        $token = $this->token();
        $this->travel(11)->hours();
        $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])->assertOk();
        $this->travel(59)->minutes();
        $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])->assertOk();
        $this->travel(1)->minutes();
        $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unlock_failures_are_rate_limited_across_tokens_for_the_account(): void
    {
        $token = $this->token();
        $second = User::first()->createToken('admin_token')->plainTextToken;
        $this->travel(30)->minutes();
        for ($i = 0; $i < 5; $i++) {
            $this->api($i % 2 ? $second : $token, 'POST', '/session/unlock', ['password' => 'wrong'])
                ->assertUnprocessable();
        }
        $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])->assertStatus(429)
            ->assertHeader('Retry-After');
        $this->api($token, 'GET', '/me')->assertStatus(423);
        $this->travel(1)->minutes();
        $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])->assertOk();
    }

    public function test_locked_logout_revokes_only_current_token_and_other_session_remains_independent(): void
    {
        $token = $this->token();
        $this->travel(20)->minutes();
        $other = User::first()->createToken('admin_token')->plainTextToken;
        $this->travel(10)->minutes();
        $this->api($token, 'GET', '/me')->assertStatus(423);
        $this->api($other, 'GET', '/me')->assertOk();
        $this->api($token, 'POST', '/logout')->assertOk();
        $this->api($token, 'GET', '/session')->assertUnauthorized();
        $this->api($other, 'GET', '/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_customer_idle_logout_and_no_password_unlock(): void
    {
        $token = $this->token('customer');
        $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])->assertForbidden();
        $this->travel(29)->minutes();
        $this->api($token, 'GET', '/me')->assertOk();
        $this->travel(1)->minutes();
        $this->api($token, 'POST', '/session/activity', ['at' => now()->addDay()->timestamp])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_customer_absolute_limit_remains_24_hours_despite_activity(): void
    {
        $token = $this->token('customer');
        for ($i = 0; $i < 48; $i++) {
            $this->travel(29)->minutes();
            $this->api($token, 'POST', '/session/activity')->assertOk();
        }
        $this->travel(27)->minutes();
        $this->api($token, 'POST', '/session/activity')->assertOk();
        $this->travel(21)->minutes();
        $this->api($token, 'GET', '/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disabled_locked_accounts_cannot_unlock_and_token_is_revoked(): void
    {
        $token = $this->token('staff');
        $this->travel(30)->minutes();
        $this->api($token, 'GET', '/me')->assertStatus(423);
        User::first()->update(['is_active' => false]);
        $this->api($token, 'POST', '/session/unlock', ['password' => 'SessionTest!123'])
            ->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_sanctum_expired_tokens_are_deleted_before_route_middleware(): void
    {
        $token = $this->token('customer');
        DB::table('personal_access_tokens')->update(['expires_at' => now()->subSecond()]);
        $this->api($token, 'GET', '/session')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_cookie_only_authentication_cannot_bypass_bearer_session_rules(): void
    {
        $this->token();
        $this->actingAs(User::first(), 'web');
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
