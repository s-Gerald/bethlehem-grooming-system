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
    public function test_signup_and_pending_edits_share_unicode_name_validation_and_casing(): void
    {
        Notification::fake();
        $payload = [...$this->registrationPayload(), 'first_name' => 'MARIA CLARA', 'last_name' => 'PEÑA'];
        $registration = $this->postJson('/api/register', $payload)->assertCreated();
        $this->assertDatabaseHas('pending_customer_registrations', ['first_name' => 'Maria Clara', 'last_name' => 'Peña']);
        $this->putJson('/api/register/pending', [...$payload, 'registration_token' => $registration->json('registration_token'),
            'first_name' => 'McDonald', 'last_name' => 'de la Cruz'])->assertCreated();
        $this->assertDatabaseHas('pending_customer_registrations', ['first_name' => 'McDonald', 'last_name' => 'de la Cruz']);
        $this->postJson('/api/register', [...$this->registrationPayload(), 'email' => 'numeric@gmail.com', 'first_name' => 'Gerald２'])
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
        (require base_path('database/migrations/2026_10_05_000001_add_registration_verification_codes.php'))->up();
        (require base_path('database/migrations/2026_10_22_000001_add_registration_recovery_grants.php'))->up();
    }

    protected function tearDown(): void
    {
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
            'email' => 'new.customer@gmail.com',
        ]);
        $pendingRegistration = PendingCustomerRegistration::where(
            'email',
            'new.customer@gmail.com',
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

        $registration = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pendingRegistration = PendingCustomerRegistration::firstOrFail();
        $plainToken = $this->verificationCodeSentTo($pendingRegistration);

        $this->postJson('/api/email/verify', ['registration_token' => $registration->json('registration_token'), 'email' => $pendingRegistration->email, 'code' => $plainToken])
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
            'email' => 'new.customer@gmail.com',
        ]);
        $pendingRegistration = PendingCustomerRegistration::where(
            'email',
            'new.customer@gmail.com',
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

        $this->withAccountSession($secondSessionResponse)
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
        $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
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
            'email' => 'pending-resend@gmail.com',
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
            $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
                ->assertOk();
            $this->travel(46)->seconds();
        }

        $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
            ->assertTooManyRequests();
    }

    public function test_signup_requires_email(): void
    {
        foreach ([null, '', 'invalid-email'] as $email) {
            $payload = $this->registrationPayload();
            $payload['email'] = $email;
            $this->postJson('/api/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
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
        $registration = $this->postJson('/api/register', $payload)->assertCreated()
            ->assertJsonPath('requires_verification', true)->assertJsonMissingPath('token');
        $pending = PendingCustomerRegistration::where('email', $payload['email'])->firstOrFail();
        $this->assertSame($expectedPhone, $pending->phone);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->postJson('/api/email/verify', ['email' => $pending->email])->assertUnprocessable();
        $verified = $this->postJson('/api/email/verify', [
            'registration_token' => $registration->json('registration_token'), 'email' => $pending->email, 'code' => $this->verificationCodeSentTo($pending),
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
            $this->postJson('/api/register', [
                ...$this->registrationPayload(), 'email' => "$name@gmail.com", 'phone' => null, 'username' => null,
            ])->assertCreated();
        }
        $this->assertDatabaseCount('pending_customer_registrations', 2);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignupPhones')]
    public function test_invalid_signup_phones_are_rejected(mixed $phone): void
    {
        $this->postJson('/api/register', [...$this->registrationPayload(), 'phone' => $phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('pending_customer_registrations', 0);
    }

    public static function invalidSignupPhones(): array
    {
        return [['09123'], ['08123456789'], ['091234567890'], ['not-a-phone'], [9123456789], [[]], [['09123456789']]];
    }

    public function test_pending_phone_can_be_removed_and_added_with_rotating_edit_credentials(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        foreach (['', '09178889999'] as $phone) {
            $pending = PendingCustomerRegistration::firstOrFail();
            $oldCode = $this->verificationCodeSentTo($pending);
            $this->travel(46)->seconds();
            Notification::fake();
            $payload = [...$this->registrationPayload(), 'phone' => $phone, 'registration_token' => $response->json('registration_token')];
            $response = $this->putJson('/api/register/pending', $payload)->assertCreated();
            $this->assertSame($phone ?: null, $pending->fresh()->phone);
            $this->putJson('/api/register/pending', $payload)->assertUnprocessable();
            $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => $oldCode])->assertUnprocessable();
        }
        $pending = PendingCustomerRegistration::firstOrFail();
        $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => $this->verificationCodeSentTo($pending)])
            ->assertOk();
        $this->assertDatabaseHas('users', ['phone' => '09178889999', 'phone_verified_at' => null]);
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
        $payload = ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => $code];
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
            ->assertUnprocessable()->assertJsonPath('message', 'Incorrect code. Try again.');
        $this->assertSame(1, $pending->fresh()->email_verification_attempts);
        $this->travel(10)->minutes();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => '000000'])
            ->assertUnprocessable()->assertJsonPath('expired', false);
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code])
            ->assertUnprocessable()->assertJsonPath('message', 'This code has expired. Request a new one.');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_attempt_limit_and_resend_rotation_are_enforced(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $oldCode = $this->verificationCodeSentTo($pending);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => $oldCode])
            ->assertTooManyRequests()->assertJsonPath('message', 'Too many attempts. Try again later.');
        $this->travel(46)->seconds();
        Notification::fake();
        $hash = $pending->fresh()->email_verification_code_hash;
        $this->postJson('/api/email/resend', ['email' => $pending->email, 'registration_token' => $response->json('registration_token')])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame($hash, $pending->fresh()->email_verification_code_hash);
        Notification::assertNothingSent();
        $this->travel(10)->minutes();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertOk();
        $newCode = $this->verificationCodeSentTo($pending);
        $this->assertNotSame($oldCode, $newCode);
        $this->assertSame(0, $pending->fresh()->email_verification_attempts);
        $this->assertTrue($pending->fresh()->email_verification_expires_at->equalTo(now()->addMinutes(10)->startOfSecond()));
        $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => $newCode])->assertOk();
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
        $this->postJson('/api/register', $this->registrationPayload())->assertAccepted()
            ->assertJsonMissingPath('requires_registration_recovery')->assertJsonMissingPath('registration_token');
        $this->postJson('/api/register', [...$this->registrationPayload(), 'email' => 'different@gmail.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'username']);
        PendingCustomerRegistration::query()->delete();
        User::factory()->create(['email' => 'new.customer@gmail.com', 'phone' => '09123456789', 'username' => 'newcustomer']);
        $this->postJson('/api/register', $this->registrationPayload())->assertAccepted()
            ->assertJsonMissingPath('requires_registration_recovery')->assertJsonMissingPath('registration_token');
        $this->postJson('/api/register', [...$this->registrationPayload(), 'email' => 'different@gmail.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'username']);
    }

    public function test_verification_rechecks_all_unique_registration_details(): void
    {
        Notification::fake();
        foreach (['email', 'phone', 'username'] as $field) {
            $registration = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
            $pending = PendingCustomerRegistration::firstOrFail();
            $code = $this->verificationCodeSentTo($pending);
            $conflict = User::factory()->create([$field => $pending->$field]);
            $this->postJson('/api/email/verify', ['registration_token' => $registration->json('registration_token'), 'email' => $pending->email, 'code' => $code])
                ->assertUnprocessable()->assertJsonMissingPath('token');
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('pending_customer_registrations', 1);
            PendingCustomerRegistration::query()->delete();
            $conflict->delete();
            Notification::fake();
        }
    }

    public function test_failed_first_delivery_can_be_retried_without_registering_again(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        });
        $registration = $this->postJson('/api/register', $this->registrationPayload())->assertCreated()
            ->assertJsonPath('email_delivery_queued', false);
        $pending = PendingCustomerRegistration::firstOrFail();
        $this->assertNull($pending->email_verification_code_hash);
        $this->assertNull($pending->email_verification_last_sent_at);
        Notification::fake();
        $this->app->instance(Dispatcher::class, Notification::getFacadeRoot());
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertOk();
        $this->postJson('/api/email/verify', ['registration_token' => $registration->json('registration_token'), 'email' => $pending->email, 'code' => $this->verificationCodeSentTo($pending)])->assertOk();
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
        $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => $pending->email, 'code' => $code])->assertOk();
    }

    public function test_pending_details_can_only_be_corrected_with_the_private_edit_credential(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::firstOrFail();
        $oldCode = $this->verificationCodeSentTo($pending);
        $payload = $this->registrationPayload();
        $payload['email'] = 'corrected@gmail.com';
        $payload['phone'] = '09178889999';
        $payload['registration_token'] = str_repeat('0', 64);
        $this->putJson('/api/register/pending', $payload)->assertUnprocessable();
        $this->travel(46)->seconds();
        Notification::fake();
        $payload['registration_token'] = $response->json('registration_token');
        $edited = $this->putJson('/api/register/pending', $payload)->assertCreated()->assertJsonPath('email_delivery_queued', true);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertDatabaseCount('users', 0);
        $pending->refresh();
        $newCode = $this->verificationCodeSentTo($pending);
        $this->putJson('/api/register/pending', $payload)->assertUnprocessable();
        $this->postJson('/api/email/verify', ['email' => 'new.customer@gmail.com', 'code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/email/verify', ['registration_token' => $edited->json('registration_token'), 'email' => $pending->email, 'code' => $newCode])->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'corrected@gmail.com', 'phone' => '09178889999', 'phone_verified_at' => null]);
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
        $this->assertStringContainsString('This code expires in 10 minutes.', $html);
        $text = view($mail->view['text'], $mail->viewData)->render();
        $this->assertStringContainsString('This code expires in 10 minutes.', $text);
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
            $this->assertStringNotContainsString('new.customer@gmail.com', json_encode($timing));
        }
        $this->assertGreaterThanOrEqual(0, $timings[2]['transport_ms']);
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $receipt])
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->postJson('/api/email/verify', ['registration_token' => $response->json('registration_token'), 'email' => 'new.customer@gmail.com', 'code' => $matches[0]])
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
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->travel(46)->seconds();
        $this->postJson('/api/email/resend', ['email' => 'new.customer@gmail.com'])
            ->assertOk()->assertJsonPath('email_delivery_token', null);
        $this->postJson('/api/email/resend', ['email' => 'missing@gmail.com'])
            ->assertOk()->assertJsonPath('email_delivery_token', null);
        $this->travel(46)->seconds();
        $resend = $this->postJson('/api/email/resend', [
            'email' => 'new.customer@gmail.com', 'registration_token' => $response->json('registration_token'),
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
        $this->postJson('/api/email/resend', ['email' => 'new.customer@gmail.com'])->assertOk();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->postJson('/api/email/delivery-status', ['delivery_token' => $response->json('email_delivery_token')])
            ->assertOk()->assertJsonPath('status', 'superseded');
        // An already queued pre-release issuance may still store a fifteen-minute deadline.
        PendingCustomerRegistration::query()->update(['email_verification_expires_at' => now()->addMinutes(15)]);
        $this->travel(11)->minutes();
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
        $this->freezeTime();
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

    public function test_registration_code_has_exactly_ten_minutes_of_server_validity(): void
    {
        $this->freezeTime();
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationCodeSentTo($pending);
        $this->assertTrue($pending->email_verification_expires_at->equalTo($pending->email_verification_last_sent_at->copy()->addMinutes(10)));
        $this->travel(10)->minutes();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code,
            'registration_token' => $response->json('registration_token')])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_signup_responses_do_not_disclose_recovery_eligibility_or_overwrite_details(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $before = $pending->getAttributes();
        $payload = [...$this->registrationPayload(), 'first_name' => 'Attacker',
            'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'];
        $publicResponse = $this->postJson('/api/register', $payload)
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
            $this->postJson('/api/register', $payload)->assertAccepted()->assertExactJson($publicResponse);
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
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $pending->forceFill(['created_at' => now()->subHours(48)])->save();
        $publicResponse = $this->postJson('/api/email/resend', ['email' => 'unknown@gmail.com'])->assertOk()->json();
        foreach ([$pending->email, 'unknown@gmail.com'] as $email) {
            $this->travel(601)->seconds();
            Notification::fake();
            $this->postJson('/api/email/resend', ['email' => $email])->assertOk()->assertExactJson($publicResponse);
            Notification::assertNothingSent();
        }
        foreach ([['email_verified_at' => null], [], ['is_active' => false], ['is_archived' => true]] as $index => $attributes) {
            $this->travel(601)->seconds();
            $user = User::factory()->create(['email' => "recovery{$index}@gmail.com", ...$attributes]);
            $this->postJson('/api/email/resend', ['email' => $user->email])->assertOk()->assertExactJson($publicResponse);
        }
    }

    public function test_email_proof_is_required_before_recovery_guidance_and_saved_details_are_returned(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $this->postJson('/api/register', [...$this->registrationPayload(), 'first_name' => 'Attacker',
            'password' => 'attacker-password', 'password_confirmation' => 'attacker-password'])
            ->assertAccepted()->assertJsonPath('requires_verification', true)
            ->assertJsonMissingPath('requires_registration_recovery')
            ->assertJsonMissingPath('registration_token')->assertJsonMissingPath('details')->assertJsonMissingPath('token');
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => 'invalid'])
            ->assertUnprocessable()->assertJsonMissingPath('requires_registration_recovery')->assertJsonMissingPath('details');
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $this->verificationCodeSentTo($pending)])
            ->assertOk()->assertJsonPath('requires_registration_review', true)->assertJsonPath('details.first_name', 'New')
            ->assertJsonStructure(['registration_recovery_token'])->assertJsonMissingPath('token');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_lost_edit_credential_requires_ownership_then_explicit_reviewed_completion(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationCodeSentTo($pending);
        $review = $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code])
            ->assertOk()->assertJsonPath('requires_registration_review', true)
            ->assertJsonMissingPath('token')->assertJsonMissingPath('details.password_hash');
        $grant = $review->json('registration_recovery_token');
        $this->assertSame(64, strlen($grant));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code])->assertUnprocessable();
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
        $this->freezeTime();
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $review = $this->postJson('/api/email/verify', ['email' => $pending->email,
            'code' => $this->verificationCodeSentTo($pending)])->assertOk();
        $this->travel(10)->minutes();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(),
            'registration_recovery_token' => $review->json('registration_recovery_token')])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_customer_registrations', 1);
    }

    public function test_retention_is_based_on_creation_and_expired_identifiers_are_released(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $pending->forceFill(['created_at' => now()->subHours(48), 'updated_at' => now()])->save();
        $oldCode = $this->verificationCodeSentTo($pending);
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->assertNotSame($pending->getKey(), PendingCustomerRegistration::query()->sole()->getKey());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_recovery_prefers_existing_user_and_preserves_its_identity(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
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
        $this->travel(46)->seconds();
        Notification::fake();
        $this->postJson('/api/email/resend', ['email' => $legacy->email])->assertOk();
        $code = $this->verificationCodeSentTo($legacy);
        $review = $this->postJson('/api/email/verify', ['email' => $legacy->email, 'code' => $code])
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

    public function test_registration_code_works_just_before_ten_minutes_but_not_after(): void
    {
        $this->freezeTime();
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $this->travel(599)->seconds();
        $this->postJson('/api/email/verify', ['email' => $pending->email,
            'registration_token' => $response->json('registration_token'),
            'code' => $this->verificationCodeSentTo($pending)])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_new_legacy_registration_codes_expire_in_ten_minutes(): void
    {
        Notification::fake();
        $legacy = User::factory()->unverified()->create();
        $this->postJson('/api/email/resend', ['email' => $legacy->email])->assertOk();
        $legacy->refresh();
        $this->assertSame(600, $legacy->email_verification_expires_at->timestamp - $legacy->email_verification_last_sent_at->timestamp);
        $code = $this->verificationCodeSentTo($legacy);
        $this->travel(601)->seconds();
        $this->postJson('/api/email/verify', ['email' => $legacy->email, 'code' => $code])->assertUnprocessable();
        $this->assertNull($legacy->fresh()->email_verified_at);
    }

    public function test_resend_does_not_extend_retention_and_expired_rows_are_not_mailed(): void
    {
        $this->useDatabaseMailQueue();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $pending->forceFill(['created_at' => now()->subHours(48)->addSeconds(60)])->save();
        $created = $pending->created_at->timestamp;
        $this->travel(46)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertOk();
        $this->assertSame($created, $pending->fresh()->created_at->timestamp);
        $this->travel(15)->seconds();
        // The worker shares this process with PHPUnit, whose full-suite budget is 512 MB.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 512])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->travel(31)->seconds();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertOk()->assertJsonPath('email_delivery_token', null);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(1, PendingCustomerRegistration::pruneExpired());
    }

    public function test_verified_user_collision_never_uses_pending_credentials_or_deletes_the_user(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationCodeSentTo($pending);
        $verified = User::factory()->create(['email' => $pending->email]);
        $before = $verified->fresh()->getAttributes();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code])->assertUnprocessable();
        Notification::fake();
        $this->postJson('/api/email/resend', ['email' => $pending->email])->assertOk();
        Notification::assertNothingSent();
        $this->assertSame($before, $verified->fresh()->getAttributes());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_pending_customers_are_not_listed_as_registered_and_cannot_reset_passwords(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->assertSame(0, User::registeredCustomer()->count());
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationCodeSentTo($pending);
        Notification::fake();
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
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $review = $this->postJson('/api/email/verify', ['email' => $pending->email,
            'code' => $this->verificationCodeSentTo($pending)])->assertOk();
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
        $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
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

    public function test_overlapping_verification_requests_consume_one_pending_registration_on_sqlite(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $payload = json_encode(['email' => $pending->email, 'code' => $this->verificationCodeSentTo($pending),
            'registration_token' => $response->json('registration_token')], JSON_THROW_ON_ERROR);
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
                $request = Illuminate\Http\Request::create('/api/email/verify', 'POST', json_decode($payload, true),
                    server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
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

    public function test_pre_release_fifteen_minute_code_cannot_outlive_the_ten_minute_limit(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $code = $this->verificationCodeSentTo($pending);
        $pending->update(['email_verification_expires_at' => $pending->email_verification_last_sent_at->copy()->addMinutes(15)]);
        $this->travel(601)->seconds();
        $this->postJson('/api/email/verify', ['email' => $pending->email, 'code' => $code,
            'registration_token' => $response->json('registration_token')])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unsupported_email_is_rejected_before_pending_registration_or_otp(): void
    {
        Notification::fake();
        $this->postJson('/api/register', [...$this->registrationPayload(), 'email' => '123@123.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'Use Gmail, Outlook, Hotmail, Live, Yahoo, or iCloud.');
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_mixed_case_plus_alias_keeps_its_identity_through_verification_and_sign_in(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', [...$this->registrationPayload(), 'email' => '  Gerald+Clinic@GMAIL.COM '])
            ->assertCreated()->assertJsonPath('email', 'Gerald+Clinic@gmail.com');
        $pending = PendingCustomerRegistration::query()->sole();
        $this->assertSame('Gerald+Clinic@gmail.com', $pending->email);
        $this->postJson('/api/email/verify', ['email' => 'gerald+clinic@gmail.com',
            'registration_token' => $response->json('registration_token'), 'code' => $this->verificationCodeSentTo($pending)])
            ->assertOk()->assertJsonPath('user.email', 'Gerald+Clinic@gmail.com');
        $this->postJson('/api/sign-in', ['identifier' => 'GERALD+CLINIC@gmail.com', 'password' => 'strong-password'])
            ->assertStatus(202)->assertJsonPath('requires_login_confirmation', true);
    }

    public function test_unchanged_legacy_email_can_correct_registration_details_but_new_email_must_pass_policy(): void
    {
        Notification::fake();
        $created = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $pending = PendingCustomerRegistration::query()->sole();
        $pending->update(['email' => 'ab@legacy.example']);
        $this->travel(46)->seconds();
        $payload = [...$this->registrationPayload(), 'email' => 'ab@legacy.example', 'first_name' => 'Corrected',
            'registration_token' => $created->json('registration_token')];
        $edited = $this->putJson('/api/register/pending', $payload)->assertCreated();
        $this->assertSame('ab@legacy.example', $pending->fresh()->email);
        $this->putJson('/api/register/pending', [...$payload, 'email' => 'new@unsupported.example',
            'registration_token' => $edited->json('registration_token')])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('ab@legacy.example', $pending->fresh()->email);
    }

    public function test_changed_registration_email_cannot_duplicate_an_account_by_local_part_case(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'Registered@gmail.com']);
        $created = $this->postJson('/api/register', $this->registrationPayload())->assertCreated();
        $this->putJson('/api/register/pending', [...$this->registrationPayload(), 'email' => 'registered@GMAIL.COM',
            'registration_token' => $created->json('registration_token')])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('new.customer@gmail.com', PendingCustomerRegistration::query()->sole()->email);
    }

    public function test_legacy_email_still_enters_the_existing_sign_in_challenge(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'ab@legacy.example', 'password_hash' => Hash::make('strong-password')]);
        $this->postJson('/api/sign-in', ['identifier' => 'ab@legacy.example', 'password' => 'strong-password'])
            ->assertAccepted()->assertJsonPath('requires_login_confirmation', true);
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
