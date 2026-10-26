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
        foreach (['services', 'personal_access_tokens', 'login_email_challenges', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function login(string $role, bool $remember = true): string
    {
        $this->app['auth']->forgetGuards();

        $pending = $this->postJson('/api/sign-in', [
            'identifier' => "$role@example.test", 'password' => 'SessionTest!123', 'remember' => $remember,
        ])->assertAccepted()->assertJsonMissingPath('token')->assertCookieMissing('bethlehem_account_session');
        $user = User::where('email', "$role@example.test")->firstOrFail();
        $code = Notification::sent($user, ConfirmLoginNotification::class)->last()->code;

        $confirmed = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $pending->json('login_poll_token'), 'code' => $code,
        ])->assertOk()->assertJsonPath('user.role', $user->role);
        $this->assertSame($user->clinicPermissions(), $confirmed->json('user.clinic_permissions'));
        $this->assertSame($user->staffPermissions(), $confirmed->json('user.staff_permissions'));

        $credential = $confirmed->getCookie('bethlehem_account_session', false)->getValue();
        $cookieExpiry = $confirmed->getCookie('bethlehem_account_session', false)->getExpiresTime();
        if ($user->role === 'customer' && ! $remember) $this->assertSame(0, $cookieExpiry);
        else $this->assertGreaterThan(now()->timestamp, $cookieExpiry);
        $this->assertSame(explode('|', $credential)[0].'|cookie', $confirmed->json('token'));
        $this->browserCookies[$confirmed->json('token')] = $credential;
        return $confirmed->json('token');
    }

    private function requestAs(string $token, string $method, string $uri, array $data = [])
    {
        // Each request is a fresh browser HTTP request, not a cached test guard.
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie('bethlehem_account_session', $this->browserCookies[$token])->withCredentials();

        return $this->json($method, $uri, $data, ['Authorization' => 'Bearer '.$token]);
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

    public function test_overlapping_verified_logins_leave_only_the_latest_session_active(): void
    {
        $pending = $this->postJson('/api/sign-in', ['identifier' => 'admin@example.test', 'password' => 'SessionTest!123'])->assertAccepted();
        $code = Notification::sent(User::where('role', 'admin')->firstOrFail(), ConfirmLoginNotification::class)->last()->code;
        $payload = json_encode(['poll_token' => $pending->json('login_poll_token'), 'code' => $code], JSON_THROW_ON_ERROR);
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
                $request = Illuminate\Http\Request::create('/api/email/login/confirm', 'POST', json_decode($payload, true),
                    server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
                echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'busy' => $busy,
                    'public_id' => json_decode($response->getContent(), true)['token'] ?? null]);
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
            $this->assertSame(200, $two['status']);
            $this->assertNotSame($one['public_id'], $two['public_id']);
            $connection = new \PDO('sqlite:'.$database);
            $this->assertSame(2, (int) $connection->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn());
            $active = $connection->query('SELECT id FROM personal_access_tokens WHERE replaced_at IS NULL')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame([explode('|', $two['public_id'])[0]], array_map('strval', $active));
        } finally {
            $first?->stop(); $second?->stop(); $connection = null;
            foreach (['sessions.sqlite', 'sessions.sqlite-journal', 'sessions.sqlite-wal', 'sessions.sqlite-shm', 'first-lock', 'second-attempt'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
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
