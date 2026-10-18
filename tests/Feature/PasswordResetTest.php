<?php

namespace Tests\Feature;

use App\Models\PasswordResetRequest;
use App\Models\User;
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
    }

    protected function tearDown(): void
    {
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
                'If eligible, a verification code has been sent.',
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

        $this->postJson('/api/password/reset', [
            'token' => $grant,
            'password' => 'AnotherPass!234',
            'password_confirmation' => 'AnotherPass!234',
        ])->assertUnprocessable();
    }

    public static function passwordResetRoles(): array
    {
        return [['customer'], ['admin']];
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
                    'If eligible, a verification code has been sent.',
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
            ->assertJsonPath('message', 'If eligible, a verification code has been sent.');
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
            ->assertJsonPath('message', 'If eligible, a verification code has been sent.');

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
