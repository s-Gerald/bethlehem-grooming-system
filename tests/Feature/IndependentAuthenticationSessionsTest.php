<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Notifications\ConfirmLoginNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndependentAuthenticationSessionsTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        foreach (['services', 'personal_access_tokens', 'login_email_challenges', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function login(string $role): string
    {
        $this->app['auth']->forgetGuards();

        $pending = $this->postJson('/api/sign-in', [
            'identifier' => "$role@example.test", 'password' => 'SessionTest!123',
        ])->assertAccepted()->assertJsonMissingPath('token');
        $user = User::where('role', $role)->firstOrFail();
        $code = Notification::sent($user, ConfirmLoginNotification::class)->last()->code;

        return $this->postJson('/api/email/login/confirm', [
            'poll_token' => $pending->json('login_poll_token'), 'code' => $code,
        ])->assertOk()->assertJsonPath('user.role', $role)->json('token');
    }

    private function requestAs(string $token, string $method, string $uri, array $data = [])
    {
        // Each request is a fresh browser HTTP request, not a cached test guard.
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, ['Authorization' => 'Bearer '.$token]);
    }

    public static function loginOrders(): array
    {
        return [['admin', 'customer'], ['customer', 'admin']];
    }

    #[DataProvider('loginOrders')]
    public function test_both_login_orders_keep_both_tokens_and_catalogue_access(string $first, string $second): void
    {
        $tokens = [$first => $this->login($first), $second => $this->login($second)];
        $this->assertNotSame($tokens[$first], $tokens[$second]);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        for ($round = 0; $round < 3; $round++) {
            foreach ($tokens as $role => $token) {
                $this->requestAs($token, 'GET', '/api/me')->assertOk()->assertJsonPath('user.role', $role);
                $this->requestAs($token, 'GET', '/api/grooming/services')->assertOk();
            }
        }
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->requestAs($tokens[$second], 'POST', '/api/logout')->assertOk();
        $this->requestAs($tokens[$second], 'GET', '/api/me')->assertUnauthorized();
        $this->requestAs($tokens[$first], 'GET', '/api/me')->assertOk()->assertJsonPath('user.role', $first);
        $this->requestAs($tokens[$first], 'GET', '/api/grooming/services')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_logout_only_revokes_the_requesting_token_even_for_the_same_account(): void
    {
        $normal = $this->login('admin');
        $private = $this->login('admin');
        $this->requestAs($normal, 'POST', '/api/logout')->assertOk();
        $this->requestAs($normal, 'GET', '/api/me')->assertUnauthorized();
        $this->requestAs($private, 'GET', '/api/me')->assertOk()->assertJsonPath('user.role', 'admin');
        $this->assertDatabaseCount('personal_access_tokens', 1);
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
