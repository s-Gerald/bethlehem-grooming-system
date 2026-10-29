<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminClinicController;
use App\Models\LoginEmailChallenge;
use App\Models\PendingStaffAccount;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\SetUpStaffPasswordNotification;
use App\Notifications\ConfirmLoginNotification;
use App\Services\PendingStaffAccountService;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminStaffAccountManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 50)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('staff_type', 20)->nullable();
            $table->string('staff_subrole', 30)->nullable();
            $table->string('customer_tier')->default('new');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::create('pending_customer_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 50)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->unique();
            $table->string('password_hash');
            $table->string('email_verification_token', 64)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pending_staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('requested_by_user_id');
            $table->string('staff_type', 20);
            $table->string('staff_subrole', 30)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('username', 50)->nullable()->unique();
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('code_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });

        Schema::create('password_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });

        Schema::create('login_email_challenges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('poll_token_hash', 64)->nullable()->unique();
            $table->boolean('remember_me')->default(true);
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_30_000001_isolate_login_email_challenges.php'))->up();
        (require base_path('database/migrations/2026_10_30_000002_create_account_security_incidents.php'))->up();

        Schema::create('privileged_credential_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('requested_by_user_id');
            $table->unsignedInteger('target_user_id');
            $table->string('target_role');
            $table->string('code_hash')->nullable();
            $table->timestamp('confirmed_at')->nullable();
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
        (require database_path('migrations/2026_10_22_000001_add_registration_recovery_grants.php'))->up();
        (require base_path('database/migrations/2026_10_25_000001_add_session_replacement.php'))->up();
        (require base_path('database/migrations/2026_10_25_000002_create_staff_email_changes.php'))->up();
        (require database_path('migrations/2026_10_25_000003_create_staff_email_recoveries.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('account_security_incidents');
        (require database_path('migrations/2026_10_25_000003_create_staff_email_recoveries.php'))->down();
        Schema::dropIfExists('staff_email_changes');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('privileged_credential_changes');
        Schema::dropIfExists('login_email_challenges');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('pending_staff_accounts');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_admin_creates_staff_with_a_single_use_password_setup_link(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'staff_subrole' => 'veterinarian',
            'first_name' => 'jOhN',
            'last_name' => 'sMiTh',
            'email' => 'new.clinic.staff@GMAIL.COM',
        ]);
        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Account setup email sent')
            ->assertJsonPath('staff.username', null)
            ->assertJsonPath('staff.staff_type', 'clinic')
            ->assertJsonPath('staff.staff_subrole', 'veterinarian')
            ->assertJsonPath('staff.is_active', false)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('password');

        $staff = User::query()->where('email', 'new.clinic.staff@gmail.com')->sole();
        $this->assertSame('jOhN', $staff->first_name);
        $this->assertSame('sMiTh', $staff->last_name);
        $this->assertSame('staff', $staff->role);
        $this->assertSame('clinic', $staff->staff_type);
        $this->assertSame('veterinarian', $staff->staff_subrole);
        $this->assertNull($staff->username);
        $this->assertNull($staff->phone);
        $this->assertTrue($staff->requiresPasswordSetup());
        $this->assertFalse($staff->is_active);
        $this->assertNull($staff->email_verified_at);
        $this->assertDatabaseCount('pending_staff_accounts', 0);

        $plainToken = $this->staffSetupLinkSentTo($staff);
        $setupRequest = PasswordResetRequest::query()->sole();
        $this->assertSame(hash('sha256', $plainToken), $setupRequest->token_hash);
        $this->assertTrue(
            $setupRequest->expires_at->between(
                now()->addHours(23)->addMinutes(59),
                now()->addHours(24)->addSeconds(5),
            ),
        );

        $this->postJson('/api/sign-in', [
            'identifier' => $staff->email,
            'password' => 'AnyPassword!234',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'password_setup_required');

        Sanctum::actingAs($staff);
        $this->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_setup_required');

        $this->postJson('/api/password/code/verify', [
            'email' => $staff->email,
            'code' => '123456',
        ])->assertUnprocessable();

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $plainToken,
            'username' => '  Clinic_Staff  ',
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])
            ->assertOk()
            ->assertJsonPath('completed_setup', true)
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonPath('user.username', 'Clinic_Staff')
            ->assertJsonStructure(['token']);

        $staff->refresh();
        $this->assertFalse($staff->requiresPasswordSetup());
        $this->assertSame('Clinic_Staff', $staff->username);
        $this->assertTrue($staff->is_active);
        $this->assertNotNull($staff->email_verified_at);
        $this->assertTrue(Hash::check('CreatedPassword!234', $staff->password_hash));
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->signInWithCode($staff, 'Clinic_Staff', 'CreatedPassword!234')
            ->assertJsonPath('user.role', 'staff');

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $plainToken,
            'password' => 'DifferentPassword!234',
            'password_confirmation' => 'DifferentPassword!234',
        ])->assertUnprocessable();
    }

    public function test_existing_email_cannot_create_a_duplicate_staff_account(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $this->createUser('staff', 'existing.staff@gmail.com');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => 'Grooming',
            'last_name' => 'Staff',
            'username' => 'anothergroomer',
            'email' => 'EXISTING.STAFF@gmail.com',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_staff_account_role_names_and_email_fields_are_required(): void
    {
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'staff_type',
                'first_name',
                'last_name',
                'email',
            ]);
    }

    public function test_invitation_ignores_admin_username_without_reserving_or_validating_it(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $this->createUser('staff', 'existing.staff@gmail.com', 'ClinicStaff');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'staff_subrole' => 'clinic_receptionist',
            'first_name' => 'Clinic',
            'last_name' => 'Staff',
            'username' => 'clinicstaff',
            'email' => 'different.staff@gmail.com',
        ])
            ->assertCreated()
            ->assertJsonPath('staff.username', null);

        $staff = User::query()->where('email', 'different.staff@gmail.com')->sole();
        $this->assertNull($staff->username);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
        $this->staffSetupLinkSentTo($staff);
    }

    public function test_staff_creation_rejects_numeric_names(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin'));
        $this->postJson('/api/admin/security/staff-accounts', ['staff_type' => 'clinic', 'staff_subrole' => 'veterinarian',
            'first_name' => 'Gerald２', 'last_name' => 'McDonald', 'email' => 'numeric.staff@gmail.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->assertDatabaseMissing('users', ['email' => 'numeric.staff@gmail.com']);
        Notification::assertNothingSent();
    }

    public function test_username_is_generated_from_normalized_staff_names_only_at_setup_completion(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => 'jUaN',
            'last_name' => 'dElA  cRuZ',
            'email' => 'generated.staff@gmail.com',
        ])->assertCreated();

        $staff = User::query()->where('email', 'generated.staff@gmail.com')->sole();
        $this->assertSame('jUaN', $staff->first_name);
        $this->assertSame('dElA cRuZ', $staff->last_name);
        $this->assertSame('grooming_receptionist', $staff->staff_subrole);
        $this->assertNull($staff->username);
        $this->assertTrue($staff->requiresPasswordSetup());
        $token = $this->staffSetupLinkSentTo($staff);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])->assertOk();
        $this->assertNull($staff->fresh()->username);
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => '   ',
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk()->assertJsonPath('user.username', 'JuanDelaCruz');
        $this->assertSame('JuanDelaCruz', $staff->fresh()->username);
        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_generated_username_uses_the_next_available_case_insensitive_suffix(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $this->createUser('staff', 'existing.staff@gmail.com', 'juandelacruz');
        $this->createUser('staff', 'second.staff@gmail.com', 'JUANDELACRUZ2');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'staff_subrole' => 'veterinarian',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'different.staff@gmail.com',
        ])
            ->assertCreated()
            ->assertJsonPath('staff.username', null);

        $staff = User::query()->where('email', 'different.staff@gmail.com')->sole();
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $this->staffSetupLinkSentTo($staff),
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk()->assertJsonPath('user.username', 'JuanDelaCruz3');
        $this->assertSame('JuanDelaCruz3', $staff->fresh()->username);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
    }

    public function test_staff_subrole_must_match_the_selected_staff_type(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $basePayload = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'subrole.staff@gmail.com',
        ];

        $this->postJson('/api/admin/security/staff-accounts', $basePayload + [
            'staff_type' => 'clinic',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_subrole');

        $this->postJson('/api/admin/security/staff-accounts', $basePayload + [
            'staff_type' => 'clinic',
            'staff_subrole' => 'grooming_receptionist',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_subrole');

        $this->postJson('/api/admin/security/staff-accounts', $basePayload + [
            'staff_type' => 'grooming',
            'staff_subrole' => 'veterinarian',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_subrole');

        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_duplicate_chosen_username_keeps_setup_pending_and_the_same_token_can_be_reused(): void
    {
        [$staff, $token] = $this->inviteStaff();
        $this->createUser('staff', 'existing@gmail.com', 'Chosen.Staff');
        $placeholder = $staff->password_hash;

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => '  chosen.STAFF  ',
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertUnprocessable()->assertJsonPath('errors.username.0', 'That username is already in use.');

        $staff->refresh();
        $this->assertNull($staff->username);
        $this->assertFalse($staff->is_active);
        $this->assertNull($staff->email_verified_at);
        $this->assertSame($placeholder, $staff->password_hash);
        $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $token)]);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])->assertOk();

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => 'Chosen.Staff-2',
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk()->assertJsonPath('user.username', 'Chosen.Staff-2');
        $this->assertTrue($staff->fresh()->is_active);
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    #[DataProvider('invalidUsernames')]
    public function test_invalid_username_does_not_activate_staff_or_consume_setup_token(mixed $username): void
    {
        [$staff, $token] = $this->inviteStaff();
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => $username,
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertNull($staff->fresh()->username);
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertTrue($staff->fresh()->requiresPasswordSetup());
        $this->assertNull($staff->fresh()->email_verified_at);
        $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $token)]);
    }

    public static function invalidUsernames(): array
    {
        return [
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 51)],
            'starts with number' => ['1Staff'],
            'starts with punctuation' => ['_Staff'],
            'contains spaces' => ['Staff Member'],
            'contains unsupported symbol' => ['Staff@Clinic'],
            'wrong type' => [['Staff']],
        ];
    }

    public function test_generated_username_reserves_room_for_suffix_within_fifty_characters(): void
    {
        $firstName = str_repeat('a', 60);
        $base = ucfirst(str_repeat('a', 50));
        [$staff, $token] = $this->inviteStaff($firstName, 'Smith');
        $this->createUser('staff', 'existing@gmail.com', $base);
        $this->createUser('staff', 'second@gmail.com', substr($base, 0, 49).'2');

        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => null,
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk()->assertJsonPath('user.username', substr($base, 0, 49).'3');
        $this->assertSame(50, strlen($staff->fresh()->username));
    }

    #[DataProvider('fallbackNames')]
    public function test_generated_username_always_complies_with_existing_format(string $firstName, string $lastName): void
    {
        [$staff, $token] = $this->inviteStaff();
        $staff->update(['first_name' => $firstName, 'last_name' => $lastName]);
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk();
        $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9._-]{2,49}$/', $staff->fresh()->username);
    }

    public static function fallbackNames(): array
    {
        return [
            'accents and punctuation' => ['José', "D'Ávila"],
            'leading numbers' => ['123Juan', 'Cruz'],
            'short names' => ['A', 'B'],
            'no usable letters' => ['---', '123'],
        ];
    }

    public function test_staff_setup_respects_pending_customer_username_reservations(): void
    {
        [$staff, $token] = $this->inviteStaff();
        DB::table('pending_customer_registrations')->insert([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'username' => 'JUANDELACRUZ',
            'email' => 'pending.customer@gmail.com',
            'phone' => '09170003001',
            'password_hash' => Hash::make('CreatedPassword!234'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => 'JuanDelaCruz',
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertUnprocessable()->assertJsonPath('errors.username.0', 'That username is already in use.');
        $this->assertNull($staff->fresh()->username);
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk()->assertJsonPath('user.username', 'JuanDelaCruz2');
    }

    public function test_failed_password_validation_does_not_assign_a_fallback_username(): void
    {
        [$staff, $token] = $this->inviteStaff();
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull($staff->fresh()->username);
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $token)]);
    }

    #[DataProvider('racingUsernames')]
    public function test_username_claimed_between_validation_and_save_is_handled(?string $username): void
    {
        [$staff, $token] = $this->inviteStaff();
        $other = $this->createUser('staff', 'other@gmail.com', 'OtherStaff');
        $raced = false;
        $updatingEvent = 'eloquent.updating: '.User::class;
        Event::listen($updatingEvent, function (User $saving) use ($staff, $other, &$raced): void {
            if ($saving->getKey() === $staff->getKey() && ! $raced) {
                $raced = true;
                // Claim the selected name after validation to exercise the real unique constraint.
                DB::table('users')->where('user_id', $other->getKey())->update(['username' => $saving->username]);
            }
        });
        Event::listen(TransactionRolledBack::class, function () use ($other, &$raced): void {
            if ($raced) {
                // Persist the winner after rollback, as a competing committed transaction would.
                DB::table('users')->where('user_id', $other->getKey())->update(['username' => 'JuanDelaCruz']);
            }
        });

        try {
            $response = $this->postJson('/api/staff/password-setup/complete', [
                'token' => $token,
                'username' => $username,
                'password' => 'CreatedPassword!234',
                'password_confirmation' => 'CreatedPassword!234',
            ]);
            $this->assertTrue($raced);
            if ($username === null) {
                $response->assertOk()->assertJsonPath('user.username', 'JuanDelaCruz2');
                $this->assertTrue($staff->fresh()->is_active);
                $this->assertDatabaseCount('password_reset_requests', 0);
            } else {
                $response->assertUnprocessable()->assertJsonPath('errors.username.0', 'That username is already in use.');
                $this->assertNull($staff->fresh()->username);
                $this->assertFalse($staff->fresh()->is_active);
                $this->assertTrue($staff->fresh()->requiresPasswordSetup());
                $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $token)]);
            }
        } finally {
            Event::forget($updatingEvent);
            Event::forget(TransactionRolledBack::class);
        }
    }

    public static function racingUsernames(): array
    {
        return ['automatic fallback retries' => [null], 'chosen username is rejected' => ['JuanDelaCruz']];
    }

    public function test_setup_rolls_back_username_password_activation_and_tokens_if_completion_fails(): void
    {
        [$staff, $token] = $this->inviteStaff();
        $placeholder = $staff->password_hash;
        $staff->createToken('old_staff_token');
        $event = 'eloquent.deleting: '.PasswordResetRequest::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Setup request could not be consumed.');
        });
        try {
            app(\App\Services\PasswordResetService::class)->completeStaffSetup($token, 'CreatedPassword!234', 'ChosenStaff');
            $this->fail('Expected setup completion to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Setup request could not be consumed.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $staff->refresh();
        $this->assertNull($staff->username);
        $this->assertSame($placeholder, $staff->password_hash);
        $this->assertFalse($staff->is_active);
        $this->assertNull($staff->email_verified_at);
        $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_active_staff_cannot_complete_setup_even_with_a_remaining_request(): void
    {
        $staff = $this->createUser('staff', 'active@gmail.com', 'ActiveStaff');
        $token = str_repeat('a', 64);
        PasswordResetRequest::query()->create([
            'user_id' => $staff->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
            'last_sent_at' => now(),
        ]);
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => 'AnotherStaff',
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertUnprocessable()->assertJsonPath('message', 'This setup link is no longer valid.');
        $this->assertSame('ActiveStaff', $staff->fresh()->username);
        $this->assertTrue(Hash::check('CurrentStaff!234', $staff->fresh()->password_hash));
    }

    public static function staffRoleChanges(): array
    {
        return [
            'vet to clinic reception' => ['veterinarian', 'clinic_receptionist'],
            'vet to grooming' => ['veterinarian', 'grooming_receptionist'],
            'clinic reception to vet' => ['clinic_receptionist', 'veterinarian'],
            'clinic reception to grooming' => ['clinic_receptionist', 'grooming_receptionist'],
            'grooming to vet' => ['grooming_receptionist', 'veterinarian'],
            'grooming to clinic reception' => ['grooming_receptionist', 'clinic_receptionist'],
        ];
    }

    #[DataProvider('staffRoleChanges')]
    public function test_role_changes_revoke_staff_sessions_and_refresh_permissions(string $from, string $to): void
    {
        $this->instance(AdminBookingController::class, Mockery::mock(AdminBookingController::class)
            ->makePartial()->shouldReceive('index')->andReturn(response()->json(['success' => true]))->getMock());
        $this->instance(AdminClinicController::class, Mockery::mock(AdminClinicController::class)
            ->makePartial()->shouldReceive('activeCases')->andReturn(response()->json(['success' => true]))->getMock());
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        $adminToken = $admin->createToken('admin_token')->plainTextToken;
        $this->withToken($adminToken)->getJson('/api/admin/bookings')->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($adminToken)->getJson('/api/admin/clinic-cases')->assertOk();
        app('auth')->forgetGuards();
        $staff = $this->createUser('staff', 'staff@gmail.com', 'Staff', $from === 'grooming_receptionist' ? 'grooming' : 'clinic');
        $staff->update(['staff_subrole' => $from]);
        $staffToken = $staff->createToken('admin_token')->plainTextToken;
        $staff->createToken('another_device');
        LoginEmailChallenge::query()->create([
            'user_id' => $staff->user_id,
            'token_hash' => hash('sha256', 'old-login'),
            'poll_token_hash' => hash('sha256', 'old-poll'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->withToken($adminToken)->patchJson("/api/admin/security/staff/{$staff->user_id}/role", [
            'staff_role' => $to,
            'current_password' => 'CurrentAdmin!234',
        ])->assertOk()
            ->assertJsonPath('message', 'Role updated successfully')
            ->assertJsonPath('staff.role', 'staff')
            ->assertJsonPath('staff.staff_type', $to === 'grooming_receptionist' ? 'grooming' : 'clinic')
            ->assertJsonPath('staff.staff_subrole', $to);

        $this->assertSame('Staff', $staff->fresh()->username);
        $this->assertTrue($staff->fresh()->is_active);
        $this->assertTrue(Hash::check('CurrentStaff!234', $staff->fresh()->password_hash));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $staff->user_id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $admin->user_id]);
        $this->assertDatabaseMissing('login_email_challenges', ['user_id' => $staff->user_id]);

        app('auth')->forgetGuards();
        $this->withToken($staffToken)->getJson('/api/settings/security/account')->assertUnauthorized();
        app('auth')->forgetGuards();
        $login = $this->signInWithCode($staff, 'Staff', 'CurrentStaff!234')
            ->assertJsonPath('user.staff_type', $to === 'grooming_receptionist' ? 'grooming' : 'clinic')
            ->assertJsonPath('user.staff_subrole', $to)
            ->assertJsonPath('user.clinic_permissions.access', true)
            ->assertJsonPath('user.clinic_permissions.clinical', $to === 'veterinarian')
            ->assertJsonPath('user.clinic_permissions.billing_view', true)
            ->assertJsonPath('user.clinic_permissions.billing_edit', $to === 'veterinarian')
            ->assertJsonPath('user.clinic_permissions.payment', true)
            ->assertJsonPath('user.staff_permissions.dashboard', true)
            ->assertJsonPath('user.staff_permissions.grooming', $to !== 'veterinarian');

        app('auth')->forgetGuards();
        $this->withAccountSession($login)->getJson('/api/settings/security/account')->assertOk();
        app('auth')->forgetGuards();
        $this->withAccountSession($login)->getJson('/api/admin/bookings')
            ->assertStatus($to !== 'veterinarian' ? 200 : 403);
        app('auth')->forgetGuards();
        $this->withAccountSession($login)->getJson('/api/admin/clinic-cases')->assertOk();
        if ($to === 'veterinarian') {
            app('auth')->forgetGuards();
            $this->withAccountSession($login)->postJson('/api/admin/walk-in')->assertForbidden();
            app('auth')->forgetGuards();
            $this->withAccountSession($login)->postJson('/api/admin/bookings/1/pay')->assertForbidden();
        }
        if ($to !== 'veterinarian') {
            app('auth')->forgetGuards();
            $this->withAccountSession($login)->postJson('/api/admin/clinic-cases/1/start')->assertForbidden();
        }
    }

    public static function invalidStaffCombinations(): array
    {
        return [
            ['grooming', 'veterinarian'],
            ['grooming', 'clinic_receptionist'],
            ['clinic', 'grooming_receptionist'],
            ['grooming', null],
            ['clinic', null],
        ];
    }

    #[DataProvider('invalidStaffCombinations')]
    public function test_invalid_role_combinations_are_rejected_by_creation_service_and_models(string $type, ?string $subrole): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        $staff = $this->createUser('staff', 'staff@gmail.com', 'Staff', 'grooming');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => $type, 'staff_subrole' => $subrole,
            'first_name' => 'New', 'last_name' => 'Staff', 'email' => 'new@gmail.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_subrole');

        foreach ([
            fn () => app(PendingStaffAccountService::class)->request($admin, $type, $subrole, 'New', 'Staff', 'new@gmail.com'),
            fn () => $staff->fresh()->update(['staff_type' => $type, 'staff_subrole' => $subrole]),
            fn () => PendingStaffAccount::query()->create(['staff_type' => $type, 'staff_subrole' => $subrole]),
        ] as $write) {
            try {
                $write();
                $this->fail('Invalid staff role combination was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('staff_subrole', $exception->errors());
            }
        }
        $this->assertSame('grooming_receptionist', $staff->fresh()->staff_subrole);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('pending_staff_accounts', 0);
        Notification::assertNothingSent();
    }

    public function test_staff_role_fields_cannot_be_cleared_after_assignment(): void
    {
        $staff = $this->createUser('staff', 'staff@gmail.com', 'Staff', 'grooming');
        try {
            $staff->update(['staff_type' => null, 'staff_subrole' => null]);
            $this->fail('Assigned staff role fields were cleared.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('staff_subrole', $exception->errors());
        }
        $this->assertSame('grooming', $staff->fresh()->staff_type);
        $this->assertSame('grooming_receptionist', $staff->fresh()->staff_subrole);
    }

    public function test_role_changes_require_the_authenticated_admin_current_password(): void
    {
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        $staff = $this->createUser('staff', 'staff@gmail.com', 'Staff', 'grooming');
        $staff->createToken('admin_token');
        Sanctum::actingAs($admin);
        $admin->update(['password_hash' => Hash::make('UpdatedAdmin!234')]);
        $url = "/api/admin/security/staff/{$staff->user_id}/role";

        $this->patchJson($url, ['staff_role' => 'veterinarian'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        foreach (['wrong', 'CurrentStaff!234', 'CurrentAdmin!234'] as $password) {
            $this->patchJson($url, ['staff_role' => 'veterinarian', 'current_password' => $password])
                ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'Incorrect password. Try again.');
            $this->assertSame('grooming', $staff->fresh()->staff_type);
            $this->assertSame('grooming_receptionist', $staff->fresh()->staff_subrole);
            $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $staff->user_id]);
        }
        foreach (['admin', 'staff', 'Clinic Staff', 'clinic', 'grooming'] as $role) {
            $this->patchJson($url, ['staff_role' => $role, 'current_password' => 'UpdatedAdmin!234'])
                ->assertUnprocessable()->assertJsonValidationErrors('staff_role');
        }
        $this->assertSame('grooming', $staff->fresh()->staff_type);
        $this->patchJson($url, ['staff_role' => 'veterinarian', 'current_password' => 'UpdatedAdmin!234'])
            ->assertOk()->assertJsonPath('staff.staff_subrole', 'veterinarian');
    }

    public function test_only_admins_can_change_non_archived_staff_roles(): void
    {
        $staff = $this->createUser('staff', 'staff@gmail.com', 'Staff', 'grooming');
        $payload = ['staff_role' => 'veterinarian', 'current_password' => 'CurrentAdmin!234'];
        $url = "/api/admin/security/staff/{$staff->user_id}/role";
        $this->patchJson($url, $payload)->assertUnauthorized();
        Sanctum::actingAs($staff);
        $this->patchJson($url, $payload)->assertForbidden();
        $customer = $this->createUser('customer', 'customer@gmail.com');
        Sanctum::actingAs($customer);
        $this->patchJson($url, $payload)->assertForbidden();
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        Sanctum::actingAs($admin);
        foreach ([$admin, $customer] as $target) {
            $this->patchJson("/api/admin/security/staff/{$target->user_id}/role", $payload)->assertNotFound();
        }
        $staff->update(['is_archived' => true]);
        $this->patchJson($url, $payload)->assertNotFound();
        $this->assertSame('grooming', $staff->fresh()->staff_type);
    }

    public function test_role_changes_preserve_pending_setup_and_deactivated_status(): void
    {
        [$pending, $setupToken] = $this->inviteStaff();
        $this->patchJson("/api/admin/security/staff/{$pending->user_id}/role", [
            'staff_role' => 'clinic_receptionist',
            'current_password' => 'CurrentAdmin!234',
        ])->assertOk()->assertJsonPath('staff.password_setup_required', true)->assertJsonPath('staff.is_active', false);
        $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $setupToken)]);
        $this->assertTrue($pending->fresh()->requiresPasswordSetup());
        $this->assertNull($pending->fresh()->username);
        $this->assertNull($pending->fresh()->email_verified_at);

        $inactive = $this->createUser('staff', 'inactive@gmail.com', 'Inactive', 'grooming');
        $inactive->update(['is_active' => false]);
        $this->patchJson("/api/admin/security/staff/{$inactive->user_id}/role", [
            'staff_role' => 'veterinarian',
            'current_password' => 'CurrentAdmin!234',
        ])->assertOk()->assertJsonPath('staff.is_active', false);
    }

    public function test_saving_an_unchanged_role_does_not_revoke_sessions(): void
    {
        $staff = $this->createUser('staff', 'staff@gmail.com', 'Staff', 'grooming');
        $staff->createToken('admin_token');
        Sanctum::actingAs($this->createUser('admin', 'admin@gmail.com', 'Admin'));
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/role", [
            'staff_role' => 'grooming_receptionist',
            'current_password' => 'CurrentAdmin!234',
        ])->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $staff->user_id]);
    }

    private function inviteStaff(string $firstName = 'Juan', string $lastName = 'Dela Cruz'): array
    {
        Notification::fake();
        Sanctum::actingAs($this->createUser('admin', 'admin@gmail.com', 'Admin'));
        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'invited.staff@gmail.com',
        ])->assertCreated()->assertJsonPath('staff.username', null);
        $staff = User::query()->where('email', 'invited.staff@gmail.com')->sole();

        return [$staff, $this->staffSetupLinkSentTo($staff)];
    }

    public function test_legacy_admin_code_cannot_verify_a_staff_email(): void
    {
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/security/staff-accounts/1/confirm', ['code' => '123456'])
            ->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_deactivated_staff_is_retained_and_cannot_sign_in_until_reactivated(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', 'Admin');
        $staff = $this->createUser(
            'staff',
            'bethlehem.staff.test@gmail.com',
            'groomingstaff',
            'grooming',
        );
        $staff->createToken('admin_token');
        LoginEmailChallenge::query()->create([
            'user_id' => $staff->user_id,
            'token_hash' => hash('sha256', 'code'),
            'poll_token_hash' => hash('sha256', 'poll'),
            'expires_at' => now()->addMinutes(15),
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", [
            'active' => false,
            'current_password' => 'CurrentAdmin!234',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Staff account deactivated.')
            ->assertJsonPath('staff.is_active', false);

        $this->assertDatabaseHas('users', [
            'user_id' => $staff->user_id,
            'email' => 'bethlehem.staff.test@gmail.com',
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $staff->user_id,
        ]);
        $this->assertDatabaseMissing('login_email_challenges', [
            'user_id' => $staff->user_id,
        ]);

        $this->postJson('/api/sign-in', [
            'identifier' => 'groomingstaff',
            'password' => 'CurrentStaff!234',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", [
            'active' => true,
            'current_password' => 'CurrentAdmin!234',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Staff account reactivated.')
            ->assertJsonPath('staff.is_active', true);

        Notification::fake();
        $this->signInWithCode($staff, 'groomingstaff', 'CurrentStaff!234')
            ->assertJsonPath('user.email', 'bethlehem.staff.test@gmail.com');
    }

    public function test_status_changes_require_the_signed_in_admin_current_password(): void
    {
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        $staff = $this->createUser('staff', 'staff@gmail.com', 'Staff', 'clinic');
        $staff->createToken('staff_token');
        Sanctum::actingAs($admin);
        $url = "/api/admin/security/staff/{$staff->user_id}/status";

        // A password change must take effect immediately for confirmations.
        $admin->password_hash = Hash::make('UpdatedAdmin!234');
        $admin->save();

        foreach ([false, true] as $active) {
            $staff->is_active = ! $active;
            $staff->save();

            $this->patchJson($url, ['active' => $active])
                ->assertUnprocessable()->assertJsonValidationErrors('current_password');

            foreach (['incorrect', 'CurrentStaff!234', 'CurrentAdmin!234'] as $password) {
                $this->patchJson($url, ['active' => $active, 'current_password' => $password])
                    ->assertUnprocessable()
                    ->assertJsonPath('errors.current_password.0', 'The admin password is incorrect.');
                $this->assertSame(! $active, $staff->fresh()->is_active);
                $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $staff->user_id]);
            }

            $this->patchJson($url, ['active' => $active, 'current_password' => 'UpdatedAdmin!234'])
                ->assertOk()->assertJsonPath('staff.is_active', $active);
            if (! $active) {
                $staff->createToken('staff_token');
            }
        }
    }

    public function test_cancel_setup_requires_admin_password_and_preserves_link_on_rejection(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => 'Pending',
            'last_name' => 'Staff',
            'email' => 'pending@gmail.com',
        ])->assertCreated();
        $staff = User::query()->where('email', 'pending@gmail.com')->firstOrFail();
        $url = "/api/admin/security/staff/{$staff->user_id}/setup";
        $reset = PasswordResetRequest::query()->where('user_id', $staff->user_id)->firstOrFail();

        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->deleteJson($url, ['current_password' => 'CurrentStaff!234'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'The admin password is incorrect.');
        $this->assertDatabaseHas('users', ['user_id' => $staff->user_id]);
        $this->assertDatabaseHas('password_reset_requests', ['token_hash' => $reset->token_hash]);

        $this->deleteJson($url, ['current_password' => 'CurrentAdmin!234'])->assertOk();
        $this->assertDatabaseMissing('users', ['user_id' => $staff->user_id]);
        $this->assertDatabaseMissing('password_reset_requests', ['token_hash' => $reset->token_hash]);
    }

    public function test_staff_cannot_create_or_change_the_status_of_staff_accounts(): void
    {
        $staff = $this->createUser('staff', 'staff@gmail.com');
        Sanctum::actingAs($staff);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'clinic',
            'first_name' => 'Another',
            'last_name' => 'Staff',
            'username' => 'anotherstaff',
            'email' => 'another.staff@gmail.com',
        ])->assertForbidden();

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", [
            'active' => false,
        ])->assertForbidden();
    }

    public function test_pending_setup_can_be_resent_and_only_the_newest_link_works(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => 'Pending',
            'last_name' => 'Staff',
            'username' => 'pendingstaff',
            'email' => 'pending.staff@outlook.com',
        ])->assertCreated();

        $staff = User::query()->where('email', 'pending.staff@outlook.com')->sole();
        $firstToken = $this->staffSetupLinkSentTo($staff);
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", ['active' => true])
            ->assertUnprocessable();

        $this->travel(61)->seconds();
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/setup-email")
            ->assertOk();
        $notifications = Notification::sent($staff, SetUpStaffPasswordNotification::class);
        $this->assertCount(2, $notifications);
        parse_str((string) parse_url($notifications->last()->setupUrl, PHP_URL_FRAGMENT), $fragment);
        $secondToken = $fragment['token'];
        $this->assertNotSame($firstToken, $secondToken);

        $this->postJson('/api/staff/password-setup/verify', ['token' => $firstToken])
            ->assertUnprocessable();
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $firstToken,
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertUnprocessable();
        $this->postJson('/api/staff/password-setup/verify', ['token' => $secondToken])
            ->assertOk();
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertNull($staff->fresh()->email_verified_at);
    }

    public function test_cancelled_setup_releases_identity_and_invalidates_link(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        Sanctum::actingAs($admin);
        $payload = [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => 'Wrong',
            'last_name' => 'Email',
            'username' => 'wrongstaff',
            'email' => 'wrong.staff@yahoo.com.ph',
        ];
        $this->postJson('/api/admin/security/staff-accounts', $payload)->assertCreated();
        $staff = User::query()->where('email', 'wrong.staff@yahoo.com.ph')->sole();
        $token = $this->staffSetupLinkSentTo($staff);

        $this->deleteJson("/api/admin/security/staff/{$staff->user_id}/setup", [
            'current_password' => 'CurrentAdmin!234',
        ])
            ->assertOk();
        $this->assertDatabaseMissing('users', ['email' => 'wrong.staff@yahoo.com.ph']);
        $this->assertDatabaseCount('password_reset_requests', 0);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])
            ->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson('/api/admin/security/staff-accounts', $payload)->assertCreated();
    }

    public function test_completed_staff_cannot_be_cancelled_or_resent(): void
    {
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        $staff = $this->createUser('staff', 'active.staff@outlook.com', 'activestaff');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/security/staff/{$staff->user_id}/setup", [
            'current_password' => 'CurrentAdmin!234',
        ])
            ->assertUnprocessable();
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/setup-email")
            ->assertNotFound();
        $this->assertDatabaseHas('users', ['user_id' => $staff->user_id]);
    }

    public function test_expired_setup_link_keeps_staff_pending_until_setup_completes(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => 'Expired',
            'last_name' => 'Invite',
            'username' => 'expiredstaff',
            'email' => 'expired.staff@icloud.com',
        ])->assertCreated();
        $staff = User::query()->where('email', 'expired.staff@icloud.com')->sole();
        $token = $this->staffSetupLinkSentTo($staff);

        $this->travel(25)->hours();
        $this->getJson('/api/admin/security/accounts')
            ->assertOk()
            ->assertJsonPath('staff.0.password_setup_required', true)
            ->assertJsonPath('staff.0.setup_link_expired', true)
            ->assertJsonPath('staff.0.is_active', false);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('expired', true);
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token,
            'username' => 'ChosenStaff',
            'password' => 'CreatedPassword!234',
            'password_confirmation' => 'CreatedPassword!234',
        ])->assertUnprocessable()->assertJsonPath('expired', true);
        $this->assertNull($staff->fresh()->username);
        $this->assertNull($staff->fresh()->email_verified_at);
        $this->assertFalse($staff->fresh()->is_active);
    }

    public function test_invalid_staff_email_is_rejected_before_invitation(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->createUser('admin', 'admin@gmail.com', 'Admin'));
        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist',
            'first_name' => 'Invalid',
            'last_name' => 'Email',
            'email' => 'invalid@-example..com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        Notification::assertNothingSent();
    }

    #[DataProvider('pendingEmailRoles')]
    public function test_pending_email_correction_preserves_identity_and_rotates_setup_link(string $type, string $subrole): void
    {
        [$admin, $staff, $oldToken] = $this->pendingEmailFixture($type, $subrole);
        $original = $staff->getAttributes();
        $admin->createToken('admin');
        $other = $this->createUser('staff', 'other@gmail.com', 'Other', 'grooming');
        $other->createToken('staff');

        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => '  corrected@GMAIL.COM  ', 'current_password' => 'CurrentAdmin!234',
        ])->assertOk()
            ->assertJsonPath('staff.user_id', $staff->user_id)
            ->assertJsonPath('staff.email', 'corrected@gmail.com')
            ->assertJsonPath('staff.password_setup_required', true)
            ->assertJsonPath('staff.is_active', false)
            ->assertJsonMissingPath('token');

        $staff->refresh();
        foreach (['user_id', 'first_name', 'last_name', 'username', 'password_hash', 'role', 'staff_type', 'staff_subrole', 'email_verified_at'] as $field) {
            $this->assertSame($original[$field], $staff->getAttributes()[$field]);
        }
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $notification = Notification::sent($staff, SetUpStaffPasswordNotification::class)->last();
        parse_str((string) parse_url($notification->setupUrl, PHP_URL_FRAGMENT), $fragment);
        $newToken = $fragment['token'];
        $this->assertNotSame($oldToken, $newToken);
        Notification::assertSentTo($staff, SetUpStaffPasswordNotification::class,
            fn ($sent, $channels, $recipient) => $sent === $notification && $recipient->email === 'corrected@gmail.com');
        $this->postJson('/api/staff/password-setup/verify', ['token' => $oldToken])->assertUnprocessable();
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $oldToken, 'username' => 'CorrectedStaff',
            'password' => 'CreatedPassword!234', 'password_confirmation' => 'CreatedPassword!234',
        ])->assertUnprocessable();
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $newToken, 'username' => 'CorrectedStaff',
            'password' => 'CreatedPassword!234', 'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk();
        $this->assertTrue($staff->fresh()->is_active);
        $this->assertNotNull($staff->fresh()->email_verified_at);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $newToken])->assertUnprocessable();
    }

    public static function pendingEmailRoles(): array
    {
        return [['clinic', 'veterinarian'], ['clinic', 'clinic_receptionist'], ['grooming', 'grooming_receptionist']];
    }

    public function test_pending_email_correction_rejects_bad_email_password_and_unrelated_fields(): void
    {
        [, $staff, $token] = $this->pendingEmailFixture();
        $this->createUser('staff', 'USED@gmail.com', 'Used', 'clinic');
        DB::table('pending_customer_registrations')->insert([
            'first_name' => 'Pending', 'last_name' => 'Customer', 'email' => 'reserved@gmail.com',
            'phone' => '09170009999', 'password_hash' => 'unused',
            'email_verification_expires_at' => now()->addMinutes(15),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['invalid@-example..com', 'invalid', str_repeat('a', 150).'@gmail.com', ' USED@gmail.com ', 'reserved@gmail.com', ' WRONG@gmail.com '] as $email) {
            $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
                'email' => $email, 'current_password' => 'CurrentAdmin!234',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->travel(11)->minutes();
        foreach ([null, '', 'incorrect', 'CurrentStaff!234'] as $password) {
            $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
                'email' => 'corrected@gmail.com', 'current_password' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234', 'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertSame('wrong@gmail.com', $staff->fresh()->email);
        $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $token)]);
        Notification::assertSentTimes(SetUpStaffPasswordNotification::class, 1);
    }

    public function test_pending_email_correction_rejects_ineligible_accounts_and_requesters(): void
    {
        [$admin, $staff] = $this->pendingEmailFixture();
        $url = "/api/admin/security/staff/{$staff->user_id}/setup-email";
        $payload = ['email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234'];
        $staff->is_archived = true;
        $staff->save();
        $this->patchJson($url, $payload)->assertNotFound();
        $staff->is_archived = false;
        $staff->password_hash = Hash::make('CompletedStaff!234');
        $staff->email_verified_at = now();
        foreach ([true, false] as $active) {
            $staff->is_active = $active;
            $staff->save();
            $this->patchJson($url, $payload)->assertUnprocessable();
        }
        $this->patchJson("/api/admin/security/staff/{$admin->user_id}/setup-email", $payload)->assertNotFound();
        Sanctum::actingAs($staff);
        $this->patchJson($url, $payload)->assertForbidden();
        Sanctum::actingAs($admin);
        $admin->is_active = false;
        $admin->save();
        $this->patchJson($url, $payload)->assertForbidden();
        $this->assertSame('wrong@gmail.com', $staff->fresh()->email);
    }

    public function test_pending_email_correction_rolls_back_when_mail_cannot_be_queued(): void
    {
        [, $staff, $token] = $this->pendingEmailFixture();
        $before = PasswordResetRequest::query()->sole()->getAttributes();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('private SMTP failure'));
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234',
        ])->assertStatus(503)->assertDontSee('private SMTP failure');
        $this->assertSame('wrong@gmail.com', $staff->fresh()->email);
        $this->assertSame($before, PasswordResetRequest::query()->sole()->getAttributes());
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])->assertOk();
    }

    public function test_correction_then_resend_uses_corrected_mailbox_and_cancel_still_works(): void
    {
        [, $staff, $oldToken] = $this->pendingEmailFixture();
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234',
        ])->assertOk();
        $corrected = Notification::sent($staff, SetUpStaffPasswordNotification::class)->last();
        parse_str((string) parse_url($corrected->setupUrl, PHP_URL_FRAGMENT), $fragment);
        $this->travel(61)->seconds();
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/setup-email")->assertOk();
        $this->postJson('/api/staff/password-setup/verify', ['token' => $fragment['token']])->assertUnprocessable();
        $this->postJson('/api/staff/password-setup/verify', ['token' => $oldToken])->assertUnprocessable();
        $latest = Notification::sent($staff, SetUpStaffPasswordNotification::class)->last();
        parse_str((string) parse_url($latest->setupUrl, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $fragment['token']])->assertOk();
        $this->assertSame('corrected@gmail.com', $staff->fresh()->email);
        $this->deleteJson("/api/admin/security/staff/{$staff->user_id}/setup", ['current_password' => 'CurrentAdmin!234'])->assertOk();
        $this->postJson('/api/staff/password-setup/verify', ['token' => $fragment['token']])->assertUnprocessable();
    }

    public function test_pending_email_correction_rechecks_setup_and_admin_password_from_stale_models(): void
    {
        [$admin, $staff, $token] = $this->pendingEmailFixture();
        $this->postJson('/api/staff/password-setup/complete', [
            'token' => $token, 'username' => 'CompletedStaff',
            'password' => 'CreatedPassword!234', 'password_confirmation' => 'CreatedPassword!234',
        ])->assertOk();
        try {
            app(PendingStaffAccountService::class)->correctEmail($admin, $staff, 'corrected@gmail.com', 'CurrentAdmin!234');
            $this->fail('A completed account must be rechecked at mutation time.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account', $exception->errors());
        }
        $this->assertSame('wrong@gmail.com', $staff->fresh()->email);
        User::query()->whereKey($admin->getKey())->update(['password_hash' => Hash::make('UpdatedAdmin!234')]);
        try {
            app(PendingStaffAccountService::class)->correctEmail($admin, $staff, 'corrected@gmail.com', 'CurrentAdmin!234');
            $this->fail('The current admin password must be read again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }
    }

    public function test_expired_setup_can_be_corrected_and_an_expired_registration_does_not_reserve_email(): void
    {
        [, $staff, $oldToken] = $this->pendingEmailFixture();
        PasswordResetRequest::query()->where('user_id', $staff->user_id)->update(['expires_at' => now()->subMinute()]);
        DB::table('pending_customer_registrations')->insert([
            'first_name' => 'Expired', 'last_name' => 'Customer', 'email' => 'corrected@gmail.com',
            'phone' => '09170009999', 'password_hash' => 'unused',
            'created_at' => now()->subHour(), 'updated_at' => now(),
        ]);
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234',
        ])->assertOk()->assertJsonPath('staff.setup_link_expired', false);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $oldToken])->assertUnprocessable();
        $this->assertTrue(PasswordResetRequest::query()->sole()->expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_email_proven_registration_review_reserves_email_only_until_its_grant_expires(): void
    {
        $this->travelTo(now()->startOfSecond());
        [, $staff] = $this->pendingEmailFixture();
        DB::table('pending_customer_registrations')->insert([
            'first_name' => 'Reviewing', 'last_name' => 'Customer', 'email' => 'corrected@gmail.com',
            'phone' => '09170009999', 'password_hash' => 'unused',
            'created_at' => now()->subMinutes(61), 'updated_at' => now(),
            'registration_recovery_token_hash' => hash('sha256', str_repeat('r', 64)),
            'registration_recovery_expires_at' => now()->addMinutes(8),
        ]);
        $payload = ['email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234'];
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->travel(8)->minutes();
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", $payload)->assertOk();
    }

    public function test_correction_rejects_a_target_cancelled_after_the_details_were_loaded(): void
    {
        [$admin, $staff] = $this->pendingEmailFixture();
        $this->deleteJson("/api/admin/security/staff/{$staff->user_id}/setup", ['current_password' => 'CurrentAdmin!234'])->assertOk();
        $request = \Illuminate\Http\Request::create('/api/admin/security/staff/'.$staff->user_id.'/setup-email', 'PATCH', [
            'email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234',
        ]);
        $request->setUserResolver(fn () => $admin);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(\App\Http\Controllers\AdminSecurityController::class)->correctStaffSetupEmail($request, $staff, app(PendingStaffAccountService::class));
    }

    public function test_email_claimed_between_validation_and_save_is_a_field_error_and_preserves_the_link(): void
    {
        [, $staff, $token] = $this->pendingEmailFixture();
        $other = $this->createUser('staff', 'other@gmail.com', 'Other', 'clinic');
        $event = 'eloquent.updating: '.User::class;
        Event::listen($event, function (User $saving) use ($staff, $other): void {
            if ($saving->getKey() === $staff->getKey() && $saving->isDirty('email')) {
                DB::table('users')->where('user_id', $other->getKey())->update(['email' => $saving->email]);
            }
        });
        try {
            $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
                'email' => 'claimed@gmail.com', 'current_password' => 'CurrentAdmin!234',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertSame('wrong@gmail.com', $staff->fresh()->email);
            $this->assertDatabaseHas('password_reset_requests', ['token_hash' => hash('sha256', $token)]);
            Notification::assertSentTimes(SetUpStaffPasswordNotification::class, 1);
        } finally {
            Event::forget($event);
        }
    }

    public function test_durable_correction_mail_targets_the_new_email_and_supersedes_queued_links(): void
    {
        [, $staff, $token] = $this->pendingEmailFixture();
        (require base_path('database/migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        Notification::swap(new \Illuminate\Notifications\ChannelManager($this->app));
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => 'first@gmail.com', 'current_password' => 'CurrentAdmin!234',
        ])->assertOk();
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => 'latest@gmail.com', 'current_password' => 'CurrentAdmin!234',
        ])->assertOk();
        $this->assertDatabaseCount('jobs', 2);
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertStringNotContainsString('SetUpStaffPasswordNotification', $payload['data']['command']);
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('latest@gmail.com', $email->getTo()[0]->getAddress());
        $this->assertDatabaseCount('jobs', 0);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])->assertUnprocessable();
    }

    public function test_durable_queue_insertion_failure_rolls_back_correction_and_resend(): void
    {
        [, $staff, $token] = $this->pendingEmailFixture();
        $this->travel(61)->seconds();
        (require base_path('database/migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config(['queue.default' => 'database']);
        Notification::swap(new \Illuminate\Notifications\ChannelManager($this->app));
        DB::statement("CREATE TRIGGER reject_setup_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $before = PasswordResetRequest::query()->sole()->getAttributes();
        $this->patchJson("/api/admin/security/staff/{$staff->user_id}/setup-email", [
            'email' => 'corrected@gmail.com', 'current_password' => 'CurrentAdmin!234',
        ])->assertStatus(503);
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/setup-email")->assertStatus(503);
        $this->assertSame('wrong@gmail.com', $staff->fresh()->email);
        $this->assertSame($before, PasswordResetRequest::query()->sole()->getAttributes());
        $this->assertDatabaseCount('jobs', 0);
        $this->postJson('/api/staff/password-setup/verify', ['token' => $token])->assertOk();
    }

    #[DataProvider('overlappingSetupOperations')]
    public function test_overlapping_pending_setup_operations_are_serialized(string $firstOperation, string $secondOperation, string $firstStatus, string $secondStatus, string $finalEmail, bool $active): void
    {
        [$admin, $staff, $token] = $this->pendingEmailFixture();
        $directory = sys_get_temp_dir().'/bethlehem-setup-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/setup.sqlite';
        $first = $second = $connection = null;
        try {
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                [$script, $database, $position, $operation, $adminId, $staffId, $oldToken] = $argv;
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-setup-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'setup.sqlite' || !is_file($database)) {
                    throw new RuntimeException('Only the disposable setup test database is allowed.');
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
                Illuminate\Support\Facades\Notification::fake();
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Setup test barrier timed out.');
                };
                $busy = false;
                $connection->beforeStartingTransaction(function () use ($position, $directory, $database, $wait, &$busy) {
                    if ($position !== 'second') return;
                    $wait($directory.'/first-lock');
                    $probe = new PDO('sqlite:'.$database);
                    $probe->exec('PRAGMA busy_timeout=0');
                    try { $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK'); }
                    catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 5) throw $e; $busy = true; }
                    touch($directory.'/second-attempt');
                });
                $app['events']->listen(Illuminate\Database\Events\TransactionBeginning::class,
                    function () use ($position, $directory, $wait) {
                        if ($position !== 'first') return;
                        touch($directory.'/first-lock');
                        $wait($directory.'/second-attempt');
                    });
                $staff = App\Models\User::findOrFail($staffId);
                $admin = App\Models\User::findOrFail($adminId);
                try {
                    $status = match ($operation) {
                        'correct' => app(App\Services\PendingStaffAccountService::class)->correctEmail(
                            $admin, $staff, $position.'@gmail.com', 'CurrentAdmin!234') ? 'corrected' : 'invalid',
                        'resend' => app(App\Services\PasswordResetService::class)->resendStaffSetupLink($staff)['status'],
                        'complete' => app(App\Services\PasswordResetService::class)->completeStaffSetup(
                            $oldToken, 'CreatedPassword!234', 'CompletedStaff')['status'],
                    };
                } catch (Illuminate\Validation\ValidationException) { $status = 'rejected'; }
                $hash = $mailbox = null;
                if (Illuminate\Support\Facades\Notification::sent($staff, App\Notifications\SetUpStaffPasswordNotification::class)->isNotEmpty()) {
                    Illuminate\Support\Facades\Notification::assertSentTo($staff, App\Notifications\SetUpStaffPasswordNotification::class,
                        function ($notification, $channels, $recipient) use (&$hash, &$mailbox) {
                            parse_str((string) parse_url($notification->setupUrl, PHP_URL_FRAGMENT), $fragment);
                            $hash = hash('sha256', $fragment['token']); $mailbox = $recipient->email; return true;
                        });
                }
                echo json_encode(['pid' => getmypid(), 'status' => $status, 'busy' => $busy, 'hash' => $hash, 'mailbox' => $mailbox]);
                PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'AUTH_MAIL_DELIVERY' => 'queued'];
            $first = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'first', $firstOperation, $admin->user_id, $staff->user_id, $token], base_path(), $environment);
            $second = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'second', $secondOperation, $admin->user_id, $staff->user_id, $token], base_path(), $environment);
            $first->setTimeout(15); $second->setTimeout(15);
            $first->start(); $second->start();
            $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($one['pid'], $two['pid']);
            $this->assertTrue($two['busy'], 'The second request must overlap a locked transaction.');
            $this->assertSame($firstStatus, $one['status']);
            $this->assertSame($secondStatus, $two['status']);
            $connection = new \PDO('sqlite:'.$database);
            $account = $connection->query('SELECT email, is_active FROM users WHERE user_id = '.$staff->user_id)->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame($finalEmail, $account['email']);
            $this->assertSame($active, (bool) $account['is_active']);
            $hash = $connection->query('SELECT token_hash FROM password_reset_requests')->fetchColumn();
            if ($active) {
                $this->assertFalse($hash);
            } else {
                $this->assertSame($two['hash'] ?? $one['hash'], $hash);
                $this->assertNotSame(hash('sha256', $token), $hash);
                $this->assertSame($finalEmail, $two['mailbox'] ?? $one['mailbox']);
            }
        } finally {
            $first?->stop(); $second?->stop(); $connection = null;
            foreach (['setup.sqlite', 'setup.sqlite-journal', 'setup.sqlite-wal', 'setup.sqlite-shm', 'first-lock', 'second-attempt'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }

    public static function overlappingSetupOperations(): array
    {
        return [
            ['correct', 'correct', 'corrected', 'corrected', 'second@gmail.com', false],
            ['correct', 'resend', 'corrected', 'sent', 'first@gmail.com', false],
            ['resend', 'correct', 'sent', 'corrected', 'second@gmail.com', false],
            ['correct', 'complete', 'corrected', 'invalid', 'first@gmail.com', false],
            ['complete', 'correct', 'completed', 'rejected', 'wrong@gmail.com', true],
        ];
    }

    private function pendingEmailFixture(string $type = 'clinic', string $subrole = 'veterinarian'): array
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@gmail.com', 'Admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/security/staff-accounts', [
            'staff_type' => $type, 'staff_subrole' => $subrole,
            'first_name' => 'Pending', 'last_name' => 'Staff', 'email' => 'wrong@gmail.com',
        ])->assertCreated();
        $staff = User::query()->where('email', 'wrong@gmail.com')->sole();
        return [$admin, $staff, $this->staffSetupLinkSentTo($staff)];
    }

    private function signInWithCode(User $staff, string $identifier, string $password): \Illuminate\Testing\TestResponse
    {
        Notification::fake();
        $pending = $this->postJson('/api/sign-in', compact('identifier', 'password'))
            ->assertAccepted()->assertJsonMissingPath('token');
        $code = Notification::sent($staff, ConfirmLoginNotification::class)->sole()->code;

        return $this->postJson('/api/email/login/confirm', [
            'poll_token' => $pending->json('login_poll_token'), 'code' => $code,
        ])->assertOk();
    }

    private function staffSetupLinkSentTo(User $staff): string
    {
        $plainToken = null;

        Notification::assertSentTo(
            $staff,
            SetUpStaffPasswordNotification::class,
            function (SetUpStaffPasswordNotification $notification) use ($staff, &$plainToken): bool {
                $message = $notification->toMail($staff);
                $this->assertSame(
                    'Set Up Your Password - Bethlehem Animal Clinic',
                    $message->subject,
                );
                $this->assertSame('Set Up Your Password', $message->actionText);
                $this->assertStringContainsString(
                    'expires in 24 hours',
                    implode(' ', [...$message->introLines, ...$message->outroLines]),
                );
                $this->assertStringContainsString(
                    '/pages/client/set-up-password.html#token=',
                    $notification->setupUrl,
                );
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

    public function test_new_staff_rejects_unsupported_email_without_mail(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@legacy.example', 'Admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/security/staff-accounts', ['staff_type' => 'grooming',
            'staff_subrole' => 'grooming_receptionist', 'first_name' => 'New', 'last_name' => 'Staff', 'email' => '123@123.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        Notification::assertNothingSent();
    }

    private function createUser(
        string $role,
        string $email,
        ?string $username = null,
        ?string $staffType = null,
    ): User {
        return User::query()->create([
            'first_name' => $role === 'staff' ? 'Grooming' : 'Admin',
            'last_name' => 'Staff',
            'username' => $username,
            'email' => $email,
            'phone' => $role === 'admin' ? '09170002001' : null,
            'password_hash' => Hash::make(
                $role === 'admin' ? 'CurrentAdmin!234' : 'CurrentStaff!234',
            ),
            'role' => $role,
            'staff_type' => $staffType,
            'staff_subrole' => $staffType === 'grooming' ? 'grooming_receptionist' : ($staffType === 'clinic' ? 'clinic_receptionist' : null),
            'customer_tier' => 'new',
            'is_active' => true,
            'is_archived' => false,
            'email_verified_at' => now(),
        ]);
    }
}
