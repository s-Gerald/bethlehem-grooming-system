<?php

namespace Tests\Feature;

use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\SetUpStaffPasswordNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 50)->nullable()->unique();
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('password_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('verified_token_hash', 64)->nullable()->unique();
            $table->unsignedTinyInteger('verification_attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    #[DataProvider('passwordResetRoles')]
    public function test_active_user_receives_a_one_time_password_reset_code_and_can_set_a_new_password(string $role): void
    {
        Notification::fake();
        $user = $this->createUser($role);
        $username = $user->username;
        $user->createToken('auth_token');

        $this->postJson('/api/password/forgot', [
            'email' => ' CUSTOMER@EXAMPLE.TEST ',
        ])
            ->assertAccepted()
            ->assertJsonPath('email', 'c***@example.test')
            ->assertJsonPath(
                'message',
                'If eligible, a new code was requested. Check your email.',
            );

        $code = $this->resetCodeSentTo($user);
        $resetRequest = PasswordResetRequest::query()->sole();
        $this->assertTrue(Hash::check($code, $resetRequest->token_hash));
        $this->assertNotSame($code, $resetRequest->token_hash);
        $this->assertTrue(
            $resetRequest->expires_at->between(
                now()->addMinutes(14),
                now()->addMinutes(15)->addSeconds(5),
            ),
        );

        $grant = $this->postJson('/api/password/code/verify', [
            'email' => 'CUSTOMER@EXAMPLE.TEST',
            'code' => $code,
        ])->assertOk()->json('token');
        $this->assertSame(64, strlen($grant));
        $this->postJson('/api/password/code/verify', [
            'email' => $user->email,
            'code' => $code,
        ])->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');

        $this->postJson('/api/password/reset', [
            'token' => $grant,
            'password' => 'CurrentPass!234',
            'password_confirmation' => 'CurrentPass!234',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Choose a password that is different from the current password.',
            );

        $this->postJson('/api/password/reset', [
            'token' => $grant,
            'password' => 'UpdatedPass!234',
            'password_confirmation' => 'UpdatedPass!234',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset successfully.');

        $this->assertTrue(Hash::check('UpdatedPass!234', $user->fresh()->password_hash));
        $this->assertSame($username, $user->fresh()->username);
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertSentToTimes($user, PasswordChangedNotification::class, 1);

        $this->postJson('/api/password/reset', [
            'token' => $grant,
            'password' => 'AnotherPass!234',
            'password_confirmation' => 'AnotherPass!234',
        ])->assertUnprocessable();
        Notification::assertSentToTimes($user, PasswordChangedNotification::class, 1);
    }

    public static function passwordResetRoles(): array
    {
        return [['customer'], ['admin'], ['staff']];
    }

    public function test_unverified_customer_cannot_request_verify_or_complete_normal_password_reset(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $code = $this->resetCodeSentTo($user);
        $grant = $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $code])
            ->assertOk()->json('token');
        $user->update(['email_verified_at' => null]);
        Notification::fake();
        $this->travel(61)->seconds();
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted()
            ->assertJsonPath('message', 'If eligible, a new code was requested. Check your email.');
        Notification::assertNothingSent();
        $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $code])->assertUnprocessable();
        $this->postJson('/api/password/reset', ['token' => $grant, 'password' => 'UpdatedPass!234',
            'password_confirmation' => 'UpdatedPass!234'])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertTrue(Hash::check('CurrentPass!234', $user->fresh()->password_hash));
    }

    public function test_unknown_and_disabled_accounts_receive_the_same_generic_response_without_email(): void
    {
        Notification::fake();
        $disabled = $this->createUser('admin', 'disabled@example.test', false);
        $archived = $this->createUser('customer', 'archived@example.test');
        $archived->update(['is_archived' => true]);

        foreach (['missing@example.test', 'disabled@example.test', 'archived@example.test'] as $email) {
            $response = $this->postJson('/api/password/forgot', ['email' => $email])
                ->assertAccepted()
                ->assertJsonPath('email', $email[0].'***@example.test')
                ->assertJsonPath(
                    'message',
                    'If eligible, a new code was requested. Check your email.',
                );
            $this->assertStringNotContainsString($email, $response->getContent());
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->assertFalse($disabled->is_active);
        $this->postJson('/api/password/code/verify', [
            'email' => 'missing@example.test',
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');
        $this->postJson('/api/password/code/verify', [
            'email' => 'missing@example.test',
            'code' => 'abc',
        ])->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');
    }

    public function test_deleted_account_receives_no_code(): void
    {
        Notification::fake();
        $deleted = $this->createUser('customer', 'deleted@example.test');
        $deleted->update(['account_deleted_at' => now()]);
        $this->postJson('/api/password/forgot', ['email' => $deleted->email])
            ->assertAccepted()
            ->assertJsonPath('message', 'If eligible, a new code was requested. Check your email.');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_account_without_an_established_password_receives_no_code(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $user->update(['password_hash' => '']);
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_wrong_or_other_account_code_cannot_continue_and_five_failures_lock_the_request(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $other = $this->createUser('customer', 'other@example.test');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $code = $this->resetCodeSentTo($user);

        $this->postJson('/api/password/code/verify', [
            'email' => $other->email,
            'code' => $code,
        ])->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');

        $wrong = $code === '000000' ? '000001' : '000000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/password/code/verify', [
                'email' => $user->email,
                'code' => $wrong,
            ])->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');
        }
        $this->assertSame(5, PasswordResetRequest::query()->sole()->verification_attempts);
        $this->postJson('/api/password/code/verify', [
            'email' => $user->email,
            'code' => $code,
        ])->assertUnprocessable();
    }

    public function test_expired_password_reset_code_cannot_change_the_password(): void
    {
        Notification::fake();
        $user = $this->createUser('staff', 'staff@example.test');

        $this->postJson('/api/password/forgot', [
            'email' => $user->email,
        ])->assertAccepted();
        $code = $this->resetCodeSentTo($user);
        $this->travelTo(PasswordResetRequest::query()->sole()->expires_at);
        $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $code])
            ->assertUnprocessable();
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $code = $this->resetCodeSentTo($user);
        $grant = $this->postJson('/api/password/code/verify', [
            'email' => $user->email,
            'code' => $code,
        ])->assertOk()->json('token');
        PasswordResetRequest::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/password/code/verify', [
            'email' => $user->email,
            'code' => $code,
        ])->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');

        $this->postJson('/api/password/reset', [
            'token' => $grant,
            'password' => 'UpdatedPass!234',
            'password_confirmation' => 'UpdatedPass!234',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('expired', true);

        $this->assertTrue(Hash::check('CurrentPass!234', $user->fresh()->password_hash));
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_password_reset_requires_a_strong_confirmed_password(): void
    {
        $this->postJson('/api/password/reset', [
            'token' => str_repeat('a', 64),
            'password' => 'weak',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_forgot_password_does_not_send_a_code_or_replace_a_valid_staff_setup_link(): void
    {
        Notification::fake();
        $staff = $this->createUser('staff', 'setup.staff@example.test');
        $staff->update(['password_hash' => User::passwordSetupPlaceholder()]);
        $oldToken = str_repeat('a', 64);
        PasswordResetRequest::query()->create([
            'user_id' => $staff->user_id,
            'token_hash' => hash('sha256', $oldToken),
            'expires_at' => now()->addHours(24),
            'last_sent_at' => now(),
        ]);

        $this->postJson('/api/password/forgot', [
            'email' => $staff->email,
        ])
            ->assertAccepted()
            ->assertJsonPath('message', 'If eligible, a new code was requested. Check your email.');

        Notification::assertNotSentTo($staff, PasswordResetCodeNotification::class);
        $this->postJson('/api/password/code/verify', [
            'email' => $staff->email,
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonPath('message', 'Invalid or expired verification code.');
        $this->assertDatabaseHas('password_reset_requests', [
            'token_hash' => hash('sha256', $oldToken),
        ]);
        $this->postJson('/api/password/reset', [
            'token' => $oldToken,
            'password' => 'SetupComplete!234',
            'password_confirmation' => 'SetupComplete!234',
        ])->assertUnprocessable();
        $this->postJson('/api/staff/password-setup/verify', [
            'token' => $oldToken,
        ])->assertOk();

        $staff->refresh();
        $this->assertTrue($staff->requiresPasswordSetup());
    }

    public function test_expired_staff_setup_link_can_be_replaced_only_through_the_setup_flow(): void
    {
        Notification::fake();
        $staff = $this->createUser('staff', 'setup.staff@example.test');
        $staff->update(['password_hash' => User::passwordSetupPlaceholder()]);
        $expiredToken = str_repeat('a', 64);
        PasswordResetRequest::query()->create([
            'user_id' => $staff->user_id,
            'token_hash' => hash('sha256', $expiredToken),
            'expires_at' => now()->subMinute(),
            'last_sent_at' => now()->subDay(),
        ]);

        $this->postJson('/api/staff/password-setup/verify', [
            'token' => $expiredToken,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('expired', true)
            ->assertJsonPath('message', 'This setup link has expired. Request a new link to finish setting up your account.');

        $this->postJson('/api/staff/password-setup/request-new-link', [
            'token' => $expiredToken,
        ])
            ->assertAccepted()
            ->assertJsonPath('message', 'A new setup link has been sent to your email.');

        $newToken = $this->setupTokenSentTo($staff);
        $this->assertNotSame($expiredToken, $newToken);
        $this->assertDatabaseMissing('password_reset_requests', [
            'token_hash' => hash('sha256', $expiredToken),
        ]);
        $this->postJson('/api/staff/password-setup/verify', [
            'token' => $expiredToken,
        ])->assertUnprocessable();
        $this->postJson('/api/staff/password-setup/verify', [
            'token' => $newToken,
        ])->assertOk();

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $newToken,
            'password' => 'SetupComplete!234',
            'password_confirmation' => 'SetupComplete!234',
        ])
            ->assertOk()
            ->assertJsonPath('completed_setup', true)
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonStructure(['token']);

        $staff->refresh();
        $this->assertFalse($staff->requiresPasswordSetup());
        $this->assertTrue(Hash::check('SetupComplete!234', $staff->password_hash));
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    #[DataProvider('maskedEmails')]
    public function test_staff_setup_inspection_returns_only_a_short_fixed_email_mask(string $email, string $masked): void
    {
        $staff = $this->createUser('staff', $email, false);
        $staff->update(['password_hash' => User::passwordSetupPlaceholder()]);
        $token = str_repeat('a', 64);
        $request = PasswordResetRequest::query()->create([
            'user_id' => $staff->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
            'last_sent_at' => now(),
        ]);
        $response = $this->postJson('/api/staff/password-setup/verify', ['token' => $token])
            ->assertOk()->assertJsonPath('email', $masked);
        $this->assertStringNotContainsString($email, $response->getContent());

        $request->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])
            ->assertUnprocessable()->assertJsonPath('expired', true)->assertJsonMissingPath('email');
        $this->postJson('/api/staff/password-setup/verify', ['token' => str_repeat('b', 64)])
            ->assertUnprocessable()->assertJsonMissingPath('email');
    }

    public static function maskedEmails(): array
    {
        return [
            ['yolanda@gmail.com', 'y***@gmail.com'],
            ['gerald.santos@gmail.com', 'g***@gmail.com'],
            ['y@gmail.com', 'y***@gmail.com'],
            ['gerald.santos@clinic.example.com', 'g***@clinic.example.com'],
        ];
    }

    #[DataProvider('passwordResetRoles')]
    public function test_immediate_password_reset_mail_uses_durable_delivery_without_duplicate_sends(string $role): void
    {
        $this->useDatabaseMailQueue();
        config()->set('mail.authentication.delivery', 'immediate');
        $user = $this->createUser($role);
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        preg_match('/\b[0-9]{6}\b/', $messages[0]->getOriginalMessage()->getTextBody(), $matches);
        $this->assertNotEmpty($matches);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 0);
        $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $matches[0]])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_replaced_and_consumed_password_reset_jobs_are_suppressed(): void
    {
        $this->useDatabaseMailQueue();
        $user = $this->createUser('customer');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $this->travel(61)->seconds();
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        PasswordResetRequest::query()->update(['token_hash' => Hash::make('already-consumed')]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_a_password_reset_resend_invalidates_the_previous_code(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $oldCode = $this->resetCodeSentTo($user);
        $this->travel(61)->seconds();
        Notification::fake();
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $newCode = $this->resetCodeSentTo($user);
        $this->assertNotSame($oldCode, $newCode);
        $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $oldCode])->assertUnprocessable();
        $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $newCode])->assertOk();
    }

    public function test_staff_setup_resend_sends_only_the_current_unexpired_link(): void
    {
        $this->useDatabaseMailQueue();
        $user = $this->createUser('staff');
        $user->update(['password_hash' => User::passwordSetupPlaceholder(), 'is_active' => false]);
        PasswordResetRequest::query()->create([
            'user_id' => $user->getKey(), 'token_hash' => hash('sha256', 'old-link'),
            'expires_at' => now()->addHours(24), 'last_sent_at' => now(),
        ]);
        $service = app(\App\Services\PasswordResetService::class);
        $service->resendStaffSetupLink($user);
        $service->resendStaffSetupLink($user);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        preg_match('/set-up-password\.html#token=([a-zA-Z0-9]+)/', $messages[0]->getOriginalMessage()->getHtmlBody(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertSame('valid', $service->inspectStaffSetup($matches[1])['status']);
        $service->resendStaffSetupLink($user);
        $this->travel(25)->hours();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    private function useDatabaseMailQueue(): void
    {
        (require base_path('database/migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
    }

    public function test_password_resend_cannot_bypass_the_cooldown_by_changing_ip(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = $this->createUser('customer');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted()->assertJsonPath('resend_after', 60);
        $before = PasswordResetRequest::query()->sole()->getAttributes();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
            ->postJson('/api/password/forgot', ['email' => strtoupper($user->email)])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame($before, PasswordResetRequest::query()->sole()->getAttributes());
    }

    public function test_password_resend_reports_the_longer_endpoint_limit_after_the_last_allowed_request(): void
    {
        $this->freezeTime();
        foreach (range(1, 2) as $attempt) {
            $this->postJson('/api/password/forgot', ['email' => 'missing@example.test'])->assertAccepted()->assertJsonPath('resend_after', 60);
            $this->travel(60)->seconds();
        }
        $this->postJson('/api/password/forgot', ['email' => 'missing@example.test'])->assertAccepted()->assertJsonPath('resend_after', 780);
        $this->travel(60)->seconds();
        $this->postJson('/api/password/forgot', ['email' => 'missing@example.test'])->assertTooManyRequests()->assertHeader('Retry-After', '720');
    }

    public function test_password_resend_preserves_failed_attempts_and_queue_failure_preserves_state(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $service = app(\App\Services\PasswordResetService::class);
        $service->sendCode($user->email);
        $request = PasswordResetRequest::query()->sole();
        $request->update(['verification_attempts' => 2]);
        $this->travel(61)->seconds();
        $service->sendCode($user->email);
        $this->assertSame(2, $request->fresh()->verification_attempts);
        $before = $request->fresh()->getAttributes();
        $this->travel(61)->seconds();
        $this->mock(\Illuminate\Contracts\Notifications\Dispatcher::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('queue unavailable')));
        try {
            $service->sendCode($user->email);
            $this->fail('Expected queue failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame($before, $request->fresh()->getAttributes());
        }
    }

    public function test_password_queue_insert_failure_keeps_the_previous_issuance_and_generic_response(): void
    {
        $this->useDatabaseMailQueue();
        $user = $this->createUser('customer');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $before = PasswordResetRequest::query()->sole()->getAttributes();
        $this->travel(61)->seconds();
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER reject_auth_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $known = $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted()->assertJsonMissingPath('email_delivery_token');
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'missing@example.test'])->assertAccepted()->assertJsonMissingPath('email_delivery_token');
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($before, PasswordResetRequest::query()->sole()->getAttributes());
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_locked_password_reset_issuance_is_not_mailed(): void
    {
        $this->useDatabaseMailQueue();
        $user = $this->createUser('customer');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        PasswordResetRequest::query()->update(['verification_attempts' => 5]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_verified_grant_survives_resend_and_expires_at_its_exact_deadline(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted()
            ->assertJsonPath('expires_in', 900);
        $code = $this->resetCodeSentTo($user);
        $verified = $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $code])
            ->assertOk()->assertJsonStructure(['token', 'expires_in']);
        $grant = $verified->json('token');
        $before = PasswordResetRequest::query()->sole();
        $this->travel(61)->seconds();
        app(\App\Services\PasswordResetService::class)->sendCode($user->email);
        $this->assertSame($before->getAttributes(), $before->fresh()->getAttributes());
        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 1);
        $this->travelTo($before->expires_at);
        $this->postJson('/api/password/reset', ['token' => $grant, 'password' => 'UpdatedPass!234',
            'password_confirmation' => 'UpdatedPass!234'])->assertUnprocessable()->assertJsonPath('expired', true);
        Notification::assertNotSentTo($user, PasswordChangedNotification::class);
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertAccepted();
        $this->postJson('/api/password/code/verify', ['email' => $user->email, 'code' => $this->resetCodeSentTo($user)])->assertOk();
    }

    public function test_five_failures_cannot_be_bypassed_by_resending_but_expiry_allows_recovery(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $service = app(\App\Services\PasswordResetService::class);
        $service->sendCode($user->email);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $service->verifyCode($user->email, '000000');
        }
        $before = PasswordResetRequest::query()->sole();
        $this->travel(61)->seconds();
        $service->sendCode($user->email);
        $this->assertSame($before->getAttributes(), $before->fresh()->getAttributes());
        Notification::assertSentToTimes($user, PasswordResetCodeNotification::class, 1);
        $this->travelTo($before->expires_at);
        $service->sendCode($user->email);
        $this->assertSame(0, $before->fresh()->verification_attempts);
        $this->assertSame('valid', $service->verifyCode($user->email, $this->resetCodeSentTo($user))['status']);
    }

    public function test_password_change_confirmation_is_encrypted_queued_and_contains_no_credentials(): void
    {
        $this->useDatabaseMailQueue();
        Notification::fake();
        $user = $this->createUser('customer');
        $service = app(\App\Services\PasswordResetService::class);
        $service->sendCode($user->email);
        $code = $this->resetCodeSentTo($user);
        $grant = $service->verifyCode($user->email, $code)['token'];
        Notification::swap(new \Illuminate\Notifications\ChannelManager(app()));
        config()->set('mail.authentication.delivery', 'immediate');
        \Illuminate\Support\Facades\DB::beginTransaction();
        $this->assertSame('reset', $service->reset($grant, 'UpdatedPass!234')['status']);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count(),
            'Confirmation must wait for the outer transaction to commit.');
        \Illuminate\Support\Facades\DB::rollBack();
        $this->assertTrue(Hash::check('CurrentPass!234', $user->fresh()->password_hash));
        $this->assertSame('reset', $service->reset($grant, 'UpdatedPass!234')['status']);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $job = \Illuminate\Support\Facades\DB::table('jobs')->sole();
        $payload = json_decode($job->payload, true);
        $this->assertSame(PasswordChangedNotification::class, $payload['displayName']);
        $this->assertStringNotContainsString('PasswordChangedNotification', $payload['data']['command']);
        $queued = unserialize(app('encrypter')->decrypt($payload['data']['command']));
        $this->assertInstanceOf(PasswordChangedNotification::class, $queued->notification);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $body = $messages[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('Your password was changed', $body);
        foreach ([$code, $grant, 'UpdatedPass!234', 'CurrentPass!234'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_confirmation_queue_failure_does_not_undo_the_password_reset(): void
    {
        Notification::fake();
        $user = $this->createUser('customer');
        $user->createToken('auth_token');
        $service = app(\App\Services\PasswordResetService::class);
        $service->sendCode($user->email);
        $grant = $service->verifyCode($user->email, $this->resetCodeSentTo($user))['token'];
        $this->mock(\Illuminate\Contracts\Notifications\Dispatcher::class, fn ($mock) =>
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('queue unavailable')));
        $this->postJson('/api/password/reset', ['token' => $grant, 'password' => 'UpdatedPass!234',
            'password_confirmation' => 'UpdatedPass!234'])->assertOk();
        $this->assertTrue(Hash::check('UpdatedPass!234', $user->fresh()->password_hash));
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function setupTokenSentTo(User $user): string
    {
        $plainToken = null;

        Notification::assertSentTo(
            $user,
            SetUpStaffPasswordNotification::class,
            function (SetUpStaffPasswordNotification $notification) use (&$plainToken): bool {
                parse_str(
                    (string) parse_url($notification->setupUrl, PHP_URL_FRAGMENT),
                    $fragment,
                );
                $plainToken = $fragment['token'] ?? null;

                return is_string($plainToken) && strlen($plainToken) === 64;
            },
        );

        return $plainToken;
    }

    private function resetCodeSentTo(User $user): string
    {
        $code = null;

        Notification::assertSentTo(
            $user,
            PasswordResetCodeNotification::class,
            function (PasswordResetCodeNotification $notification) use ($user, &$code): bool {
                $message = $notification->toMail($user);
                $this->assertSame(
                    'Reset Your Password - Bethlehem Animal Clinic',
                    $message->subject,
                );
                $this->assertNull($message->actionText);
                $this->assertStringContainsString(
                    'expires in 15 minutes',
                    implode(' ', [...$message->introLines, ...$message->outroLines]),
                );
                $this->assertStringContainsString(
                    "Your verification code is: {$notification->code}",
                    implode(' ', $message->introLines),
                );
                $code = $notification->code;
                return preg_match('/^[0-9]{6}$/', $code) === 1;
            },
        );

        return $code;
    }

    private function createUser(
        string $role,
        string $email = 'customer@example.test',
        bool $active = true,
    ): User {
        return User::query()->create([
            'first_name' => ucfirst($role),
            'last_name' => 'Bethlehem',
            'username' => strtok($email, '@'),
            'email' => $email,
            'phone' => '09'.random_int(100000000, 999999999),
            'password_hash' => Hash::make('CurrentPass!234'),
            'role' => $role,
            'customer_tier' => 'new',
            'is_active' => $active,
            'is_archived' => false,
            'account_deleted_at' => null,
            'email_verified_at' => now(),
        ]);
    }
}
