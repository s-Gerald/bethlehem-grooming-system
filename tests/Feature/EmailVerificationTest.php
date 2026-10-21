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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('username', 50)->nullable()->unique();
            $table->string('phone', 20)->unique();
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
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
            $table->string('phone', 20)->unique();
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
        (require base_path('database/migrations/2026_10_05_000001_add_registration_verification_codes.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('login_email_challenges');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_registration_queues_a_six_digit_code_and_hashes_it(): void
    {
        Notification::fake();
        config()->set('app.frontend_url', 'https://clinic.example/bethlehem');

        $response = $this->postJson('/api/register', $this->registrationPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('requires_verification', true)
            ->assertJsonPath('email_delivery_queued', true)
            ->assertJsonMissingPath('token');

        $this->assertDatabaseMissing('users', [
            'email' => 'new.customer@example.test',
        ]);
        $pendingRegistration = PendingCustomerRegistration::where(
            'email',
            'new.customer@example.test',
        )->firstOrFail();

        $plainToken = $this->verificationCodeSentTo($pendingRegistration);

        $this->assertSame(6, strlen($plainToken));
        $this->assertTrue(Hash::check($plainToken, $pendingRegistration->fresh()->email_verification_code_hash));
        $this->assertNull($pendingRegistration->fresh()->email_verification_token);
        $this->assertNotSame(
            $plainToken,
            $pendingRegistration->fresh()->email_verification_code_hash,
        );
    }

    public function test_verification_creates_the_customer_account_and_logs_it_in(): void
    {
        Notification::fake();

        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pendingRegistration = PendingCustomerRegistration::firstOrFail();
        $plainToken = $this->verificationCodeSentTo($pendingRegistration);

        $this->postJson('/api/email/verify', ['email' => $pendingRegistration->email, 'code' => $plainToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'new.customer@example.test')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $user = User::where('email', 'new.customer@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('strong-password', $user->password_hash));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_verification_marks_an_active_customer_verified_and_logs_them_in(): void
    {
        $plainToken = str_repeat('a', 64);
        $user = User::factory()->unverified()->create([
            'email_verification_token' => hash('sha256', $plainToken),
            'email_verification_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.user_id', $user->user_id)
            ->assertJsonStructure(['token']);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);
        $this->assertNull($user->email_verification_expires_at);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_registration_remains_recoverable_when_notification_queueing_fails(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('Queue unavailable'));
        });

        $this->postJson('/api/register', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_delivery_queued', false)
            ->assertJsonPath('requires_verification', true);

        $this->assertDatabaseMissing('users', [
            'email' => 'new.customer@example.test',
        ]);
        $pendingRegistration = PendingCustomerRegistration::where(
            'email',
            'new.customer@example.test',
        )->firstOrFail();
        $this->assertNull($pendingRegistration->email_verification_token);
        $this->assertNull($pendingRegistration->email_verification_expires_at);
    }

    public function test_first_release_plain_tokens_remain_usable_during_the_upgrade(): void
    {
        $plainToken = str_repeat('b', 64);
        $user = User::factory()->unverified()->create([
            'email_verification_token' => $plainToken,
            'email_verification_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_expired_and_already_used_links_cannot_verify_twice(): void
    {
        $plainToken = str_repeat('c', 64);
        $user = User::factory()->unverified()->create([
            'email_verification_token' => hash('sha256', $plainToken),
            'email_verification_expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertStatus(422)
            ->assertJsonPath('expired', true);
        $this->assertNull($user->fresh()->email_verified_at);

        $user->update(['email_verification_expires_at' => now()->addHour()]);

        $this->postJson('/api/email/verify', ['token' => $plainToken])->assertOk();
        $this->postJson('/api/email/verify', ['token' => $plainToken])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
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
                ->assertForbidden()
                ->assertJsonPath('code', 'account_disabled')
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
            'email' => 'pending@example.test',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => 'pending@example.test',
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
            'email' => 'login-confirmation@example.test',
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

        $this->assertNotNull($challenge->fresh()->approved_at);
        $this->assertDatabaseCount('login_email_challenges', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // A dropped first response remains recoverable until the browser
        // acknowledges that it safely stored the returned session.
        $secondSessionResponse = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ]);
        $secondSessionResponse
            ->assertOk()
            ->assertJsonPath('approved', true)
            ->assertJsonStructure(['token']);
        $this->assertDatabaseCount('login_email_challenges', 1);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($secondSessionResponse->json('token'))
            ->postJson('/api/email/login/complete', [
                'poll_token' => $plainPollToken,
            ])
            ->assertOk();

        $this->assertDatabaseCount('login_email_challenges', 0);

        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $plainPollToken,
            'code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('token');
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

    public function test_sign_in_keeps_other_sessions_and_logout_revokes_only_current_token(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'sessions@example.test',
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
        ])->assertOk()->json('token');
        $this->withToken($firstToken)->postJson('/api/email/login/complete', [
            'poll_token' => $firstLogin->json('login_poll_token'),
        ])->assertOk();

        Notification::fake();
        $secondLogin = $this->postJson('/api/sign-in', [
            'identifier' => $user->email,
            'password' => 'strong-password',
        ])->assertAccepted();
        $secondToken = $this->postJson('/api/email/login/confirm', [
            'poll_token' => $secondLogin->json('login_poll_token'),
            'code' => $this->loginCodeSentTo($user),
        ])->assertOk()->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($firstToken)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)->getJson('/api/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->getJson('/api/me')
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
        for ($attempt = 1; $attempt <= 5; $attempt++) {
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

    public function test_resend_is_generic_and_issues_a_code_for_existing_unverified_users(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/email/resend', ['email' => strtoupper($user->email)])
            ->assertOk()
            ->assertJsonPath('success', true);

        $plainToken = $this->verificationCodeSentTo($user);
        $this->assertTrue(Hash::check($plainToken, $user->fresh()->email_verification_code_hash));

        Notification::fake();
        $this->postJson('/api/email/resend', ['email' => 'missing@example.test'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'If that email is registered and unverified, a new verification email is queued.',
            );
        Notification::assertNothingSent();
    }

    public function test_resend_issues_a_pending_registration_code(): void
    {
        Notification::fake();
        $pendingRegistration = PendingCustomerRegistration::create([
            'first_name' => 'Pending',
            'last_name' => 'Customer',
            'username' => 'pendingresend',
            'phone' => '09120000001',
            'email' => 'pending-resend@example.test',
            'password_hash' => Hash::make('strong-password'),
        ]);

        $this->postJson('/api/email/resend', [
            'email' => strtoupper($pendingRegistration->email),
        ])->assertOk();

        $plainToken = $this->verificationCodeSentTo($pendingRegistration);
        $this->assertTrue(Hash::check($plainToken, $pendingRegistration->fresh()->email_verification_code_hash));
        $this->assertNull($pendingRegistration->fresh()->email_verification_token);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_failed_resend_queueing_does_not_invalidate_the_previous_link(): void
    {
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
            ->assertOk()
            ->assertJsonPath('email_delivery_token', null);

        $user->refresh();
        $this->assertSame($oldTokenHash, $user->email_verification_token);
        $this->assertTrue($oldExpiry->equalTo($user->email_verification_expires_at));
    }

    public function test_resend_endpoint_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/email/resend', ['email' => 'missing@example.test'])
                ->assertOk();
            $this->travel(46)->seconds();
        }

        $this->postJson('/api/email/resend', ['email' => 'missing@example.test'])
            ->assertTooManyRequests();
    }

    public function test_signup_requires_both_contact_fields(): void
    {
        foreach (['email', 'phone'] as $field) {
            $payload = $this->registrationPayload();
            unset($payload[$field]);
            $this->postJson('/api/register', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 0);
    }

    public function test_signup_code_is_single_use_and_never_verifies_the_phone(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $code = $this->verificationCodeSentTo($pending);
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertStringNotContainsString($code, json_encode($pending->getAttributes()));
        $this->assertArrayNotHasKey('email_verification_code_hash', $pending->toArray());
        $payload = ['email' => $pending->email, 'code' => $code];
        $this->postJson('/api/email/verify', $payload)->assertOk();
        $this->postJson('/api/email/verify', $payload)->assertUnprocessable();
        $user = User::firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_incorrect_and_expired_signup_codes_fail(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $code = $this->verificationCodeSentTo($pending);
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => '000000'])
            ->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');
        $this->assertSame(1, $pending->fresh()->email_verification_attempts);
        $this->travel(15)->minutes();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code])
            ->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_attempt_limit_and_resend_rotation_are_enforced(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $oldCode = $this->verificationCodeSentTo($pending);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $oldCode])
            ->assertTooManyRequests()->assertJsonPath('message', 'Too many attempts. Try again later.');
        $this->travel(46)->seconds();
        Notification::fake();
        $hash = $pending->fresh()->email_verification_code_hash;
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $response->json('registration_token')])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame($hash, $pending->fresh()->email_verification_code_hash);
        Notification::assertNothingSent();
        $this->travel(15)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertOk();
        $newCode = $this->verificationCodeSentTo($pending);
        $this->assertNotSame($oldCode, $newCode);
        $this->assertSame(0, $pending->fresh()->email_verification_attempts);
        $this->assertTrue($pending->fresh()->email_verification_expires_at->equalTo(now()->addMinutes(15)->startOfSecond()));
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $newCode])->assertOk();
    }

    public function test_resend_cooldown_is_enforced_across_different_ips(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $hash = $pending->email_verification_code_hash;
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52']);
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $response->json('registration_token')])
            ->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Please wait before requesting another code.');
        $this->assertSame($hash, $pending->fresh()->email_verification_code_hash);
    }

    public function test_duplicate_contacts_and_username_are_still_protected(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->postJson('/api/register', $this->registrationPayload())->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'username']);
        PendingCustomerRegistration::query()->delete();
        User::factory()->create(['email' => 'new.customer@example.test', 'phone' => '09123456789', 'username' => 'newcustomer']);
        $this->postJson('/api/register', $this->registrationPayload())->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'username']);
    }

    public function test_verification_rechecks_all_unique_registration_details(): void
    {
        Notification::fake();
        foreach (['email', 'phone', 'username'] as $field) {
            $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
            $pending = PendingCustomerRegistration::firstOrFail();
            $code = $this->verificationCodeSentTo($pending);
            $conflict = User::factory()->create([$field => $pending->$field]);
            $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code])
                ->assertUnprocessable()->assertJsonMissingPath('token');
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('pending_customer_registrations', 0);
            $conflict->delete();
            Notification::fake();
        }
    }

    public function test_failed_first_delivery_can_be_retried_without_registering_again(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        });
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated()
            ->assertJsonPath('email_delivery_queued', false);
        $pending = PendingCustomerRegistration::firstOrFail();
        $this->assertNull($pending->email_verification_code_hash);
        $this->assertNull($pending->email_verification_last_sent_at);
        Notification::fake();
        $this->app->instance(Dispatcher::class, Notification::getFacadeRoot());
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertOk();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $this->verificationCodeSentTo($pending)])->assertOk();
    }

    public function test_failed_resend_preserves_the_previous_code_and_attempts(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $code = $this->verificationCodeSentTo($pending);
        $pending->update(['email_verification_attempts' => 2]);
        $hash = $pending->email_verification_code_hash;
        $this->travel(46)->seconds();
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        });
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $response->json('registration_token')])->assertStatus(503);
        $this->assertSame($hash, $pending->fresh()->email_verification_code_hash);
        $this->assertSame(2, $pending->fresh()->email_verification_attempts);
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code])->assertOk();
    }

    public function test_pending_details_can_only_be_corrected_with_the_private_edit_credential(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $oldCode = $this->verificationCodeSentTo($pending);
        $payload = $this->registrationPayload();
        $payload['email'] = 'corrected@example.test';
        $payload['phone'] = '09178889999';
        $payload['registration_token'] = str_repeat('0', 64);
        $this->putJson('/api/register/pending', $payload)->assertUnprocessable();
        $this->travel(46)->seconds();
        Notification::fake();
        $payload['registration_token'] = $response->json('registration_token');
        $this->putJson('/api/register/pending', $payload)->assertCreated()->assertJsonPath('email_delivery_queued', true);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertDatabaseCount('users', 0);
        $pending->refresh();
        $newCode = $this->verificationCodeSentTo($pending);
        $this->putJson('/api/register/pending', $payload)->assertUnprocessable();
        $this->postJson('/api/email/verify', ['email' => 'new.customer@example.test', 'code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $newCode])->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'corrected@example.test', 'phone' => '09178889999', 'phone_verified_at' => null]);
    }

    public function test_no_sms_endpoint_or_phone_payload_can_complete_registration(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->postJson('/api/email/verify', ['phone' => '09123456789', 'code' => '123456', 'channel' => 'sms'])->assertUnprocessable();
        $this->postJson('/api/sms/verify', ['phone' => '09123456789', 'code' => '123456'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
    }

    public function test_signup_mail_has_a_prominent_code_without_a_verification_url(): void
    {
        $mail = (new VerifyEmailNotification('123456'))->toMail((object) ['first_name' => 'Customer']);
        $this->assertSame('Your Bethlehem verification code', $mail->subject);
        $this->assertNull($mail->actionUrl);
        $html = (string) $mail->render();
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('font-size:32px', $html);
        $this->assertStringContainsString('15 minutes', $html);
        $this->assertStringNotContainsString('verify-email.html', $html);
        $this->assertStringNotContainsString('token=', $html);
    }

    public function test_already_queued_link_notifications_can_still_be_rendered(): void
    {
        $reflection = new \ReflectionClass(VerifyEmailNotification::class);
        $queued = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('verificationUrl')->setValue($queued, 'https://clinic.example/pages/client/verify-email.html#token=already-issued');
        $this->assertSame('https://clinic.example/pages/client/verify-email.html#token=already-issued', $queued->toMail((object) [])->actionUrl);
    }

    public function test_log_mailer_cannot_record_signup_codes(): void
    {
        Notification::fake();
        foreach (['log', 'failover'] as $mailer) {
            config()->set('mail.default', $mailer);
            $this->postJson('/api/register', $this->registrationPayload())->assertCreated()
                ->assertJsonPath('email_delivery_queued', false);
            Notification::assertNothingSent();
            $this->assertNull(PendingCustomerRegistration::firstOrFail()->email_verification_code_hash);
            $this->assertDatabaseCount('users', 0);
            PendingCustomerRegistration::query()->delete();
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
            'email' => 'pending@example.test',
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

    public function test_login_resend_failure_preserves_the_previous_challenge(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', [
            'identifier' => $user->email, 'password' => 'strong-password',
        ])->assertAccepted();
        $code = $this->loginCodeSentTo($user);
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
        $this->postJson('/api/email/login/confirm', [
            'poll_token' => $response->json('login_poll_token'), 'code' => $code,
        ])->assertOk();
    }

    public function test_login_resend_rotates_the_code_without_resetting_attempt_limits(): void
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
        $hash = LoginEmailChallenge::query()->sole()->token_hash;
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson('/api/email/login/resend', ['poll_token' => $pollToken])->assertTooManyRequests();
        $this->assertSame($hash, LoginEmailChallenge::query()->sole()->token_hash);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pollToken, 'code' => $oldCode])->assertStatus(422);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pollToken, 'code' => $oldCode])->assertStatus(429);
        $this->postJson('/api/email/login/confirm', ['poll_token' => $pollToken, 'code' => $newCode])->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_email_requests_are_throttled_before_replacing_the_challenge(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        foreach (range(1, 3) as $attempt) {
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
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $receipt = $response->json('email_delivery_token');
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
        preg_match('/\b[0-9]{6}\b/', $email->getTextBody(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString($matches[0], $payload);
        $this->assertSame(['queued', 'sending', 'sent'], array_column($timings, 'status'));
        foreach ($timings as $timing) {
            $this->assertSame([], array_diff(array_keys($timing), ['delivery_id', 'status', 'elapsed_ms', 'request_ms', 'transport_ms']));
            $this->assertGreaterThanOrEqual(0, $timing['elapsed_ms']);
            $this->assertStringNotContainsString($matches[0], json_encode($timing));
            $this->assertStringNotContainsString($receipt, json_encode($timing));
            $this->assertStringNotContainsString('new.customer@example.test', json_encode($timing));
        }
        $this->assertGreaterThanOrEqual(0, $timings[2]['transport_ms']);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->postJson('/api/email/verify', ['email' => 'new.customer@example.test', 'code' => $matches[0]])
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
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $receipt = $response->json('email_delivery_token');
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
        config()->set('mail.from.address', 'clinic@example.test');
        config()->set('mail.default', 'failover');
        $this->postJson('/api/sign-in', [
            'identifier' => $user->email, 'password' => 'strong-password',
        ])->assertStatus(503)->assertJsonMissingPath('token');
        $this->assertDatabaseCount('login_email_challenges', 0);
    }

    public function test_public_resend_delivery_status_does_not_enumerate_addresses(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->travel(46)->seconds();
        $this->postJson('/api/email/resend', ['email' => 'new.customer@example.test'])
            ->assertOk()->assertJsonPath('email_delivery_token', null);
        $this->postJson('/api/email/resend', ['email' => 'missing@example.test'])
            ->assertOk()->assertJsonPath('email_delivery_token', null);
        $this->travel(46)->seconds();
        $resend = $this->postJson('/api/email/resend', [
            'email' => 'new.customer@example.test', 'registration_token' => $response->json('registration_token'),
        ])->assertOk();
        $this->assertIsString($resend->json('email_delivery_token'));
        $this->postJson('/api/email/delivery-status', ['delivery_token' => str_repeat('x', 64)])
            ->assertOk()->assertJsonPath('status', 'unavailable')
            ->assertJsonMissingPath('email')->assertJsonMissingPath('code');
    }

    public function test_immediate_authentication_mail_does_not_wait_for_a_worker_or_queue_a_duplicate(): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', 'immediate');
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $response->json('email_delivery_token')])
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_replaced_and_expired_registration_jobs_do_not_send(): void
    {
        $this->useDatabaseMailQueue();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->travel(46)->seconds();
        $this->postJson('/api/email/resend', ['email' => 'new.customer@example.test'])->assertOk();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $response->json('email_delivery_token')])
            ->assertOk()->assertJsonPath('status', 'superseded');
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
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('VerifyEmailNotification', json_decode($payload, true)['data']['command']);
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/email/delivery-status', ['delivery_token' => $response->json('email_delivery_token')])
                ->assertOk()->assertJsonPath('status', 'retrying')->assertDontSee('Private SMTP');
            DB::table('jobs')->update(['available_at' => now()->timestamp]);
            $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $response->json('email_delivery_token')])
            ->assertOk()->assertJsonPath('status', 'failed');
        $this->assertDatabaseCount('users', 0);
    }

    private function useDatabaseMailQueue(): void
    {
        (require base_path('database/migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
    }

    public function test_legacy_otp_jobs_are_suppressed_but_legacy_link_mail_still_sends(): void
    {
        $this->useDatabaseMailQueue();
        $user = User::factory()->create();
        $user->notify(new ConfirmLoginNotification('123456'));
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $reflection = new \ReflectionClass(VerifyEmailNotification::class);
        $legacy = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('verificationUrl')->setValue($legacy, 'https://clinic.example/pages/client/verify-email.html#token=legacy');
        $user->notify($legacy);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_a_competing_worker_releases_the_job_without_duplicate_sending(): void
    {
        $this->useDatabaseMailQueue();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $receipt = $response->json('email_delivery_token');
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
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        PendingCustomerRegistration::query()->delete();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_queue_insert_failure_rolls_back_the_registration_issuance(): void
    {
        $this->useDatabaseMailQueue();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $before = $pending->getAttributes();
        $this->travel(46)->seconds();
        DB::statement("CREATE TRIGGER reject_auth_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        try {
            DB::transaction(fn () => app(\App\Services\RegistrationEmailCodeService::class)->send($pending));
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

    public function test_locked_registration_and_login_issuances_are_not_mailed(): void
    {
        $this->useDatabaseMailQueue();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        PendingCustomerRegistration::query()->update(['email_verification_attempts' => 5]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $user = User::factory()->create(['password_hash' => Hash::make('strong-password')]);
        $response = $this->postJson('/api/sign-in', ['identifier' => $user->email, 'password' => 'strong-password'])->assertAccepted();
        $key = 'login-code:'.hash('sha256', $response->json('login_poll_token'));
        foreach (range(1, 5) as $attempt) \Illuminate\Support\Facades\RateLimiter::hit($key, 900);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
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

    private function registrationPayload(): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Customer',
            'username' => 'newcustomer',
            'phone' => '09123456789',
            'email' => 'NEW.CUSTOMER@example.test',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ];
    }

    private function verificationCodeSentTo(User|PendingCustomerRegistration $notifiable): string
    {
        $code = null;
        Notification::assertSentTo($notifiable, VerifyEmailNotification::class,
            function (VerifyEmailNotification $notification) use (&$code) {
                $code = $notification->code;
                return true;
            });
        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        return $code;
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
