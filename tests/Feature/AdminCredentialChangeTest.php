<?php

namespace Tests\Feature;

use App\Models\PrivilegedCredentialChange;
use App\Models\User;
use App\Notifications\ConfirmPrivilegedCredentialChangeCodeNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCredentialChangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql'
            && (! preg_match('/\Abethlehem_email_test_[a-f0-9]{12}\z/', (string) config('database.connections.mysql.database'))
                || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true))) {
            throw new \RuntimeException('MySQL credential tests require a disposable local email-test database.');
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username', 50)->nullable()->unique();
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
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
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password_hash');
            $table->string('email_verification_token', 64)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('privileged_credential_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('requested_by_user_id');
            $table->unsignedInteger('target_user_id');
            $table->string('target_role');
            $table->string('new_username', 50)->nullable();
            $table->string('new_password_hash')->nullable();
            $table->boolean('changes_password')->default(false);
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->dateTime('expires_at');
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
        (require database_path('migrations/2026_08_28_000003_create_password_reset_requests_table.php'))->up();
        (require database_path('migrations/2026_09_28_000002_add_code_verification_to_password_reset_requests.php'))->up();
        (require database_path('migrations/2026_10_25_000001_add_session_replacement.php'))->up();
        (require database_path('migrations/2026_10_25_000002_create_staff_email_changes.php'))->up();
        (require database_path('migrations/2026_10_25_000003_create_staff_email_recoveries.php'))->up();
        (require database_path('migrations/2026_08_19_000002_create_login_email_challenges_table.php'))->up();
        (require database_path('migrations/2026_08_19_000003_add_polling_to_login_email_challenges_table.php'))->up();
    }

    protected function tearDown(): void
    {
        (require database_path('migrations/2026_10_25_000003_create_staff_email_recoveries.php'))->down();
        Schema::dropIfExists('staff_email_changes');
        Schema::dropIfExists('login_email_challenges');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('privileged_credential_changes');
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_security_accounts_lists_the_current_staff_record_even_without_a_username(): void
    {
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', '09170001001', 'Admin');
        $staff = $this->createUser('staff', 'staff-placeholder@gmail.com', '09170001002');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/security/accounts')
            ->assertOk()
            ->assertJsonPath('admin.username', 'Admin')
            ->assertJsonPath('admin.email', 'bethlehem.admin.test@gmail.com')
            ->assertJsonPath('staff.0.user_id', $staff->user_id)
            ->assertJsonPath('staff.0.username', null)
            ->assertJsonPath('staff.0.email', 'staff-placeholder@gmail.com');
    }

    public function test_admin_username_change_requires_current_password_and_a_purpose_specific_security_code(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', '09170001003', 'Admin');
        $originalPasswordHash = $admin->password_hash;
        $admin->createToken('admin_token');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/security/account/credential-change', [
            'current_password' => 'wrong-password',
            'username' => 'ClinicAdmin',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/admin/security/account/credential-change', [
            'current_password' => 'CurrentAdmin!234',
            'username' => 'ClinicAdmin',
        ]);
        $response
            ->assertAccepted()
            ->assertJsonPath('purpose', 'Admin username change')
            ->assertJsonPath('target_name', 'Admin Bethlehem');
        $this->assertMatchesRegularExpression(
            '/^b\*+@gmail\.com$/',
            $response->json('confirmation_email'),
        );

        $this->assertSame('Admin', $admin->fresh()->username);
        $plainCode = $this->credentialChangeCodeSentTo(
            $admin,
            'Admin username change',
            'Admin Bethlehem',
        );
        $change = PrivilegedCredentialChange::query()->sole();
        $this->assertNotSame($plainCode, $change->code_hash);
        $this->assertTrue(Hash::check($plainCode, $change->code_hash));

        $this->postJson("/api/admin/security/credential-changes/{$change->id}/confirm", [
            'code' => '999999' === $plainCode ? '888888' : '999999',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The security code is incorrect.')
            ->assertJsonPath('attempts_remaining', 4);
        $this->assertSame('Admin', $admin->fresh()->username);

        $this->postJson("/api/admin/security/credential-changes/{$change->id}/confirm", [
            'code' => $plainCode,
        ])
            ->assertOk()
            ->assertJsonPath('purpose', 'Admin username change')
            ->assertJsonPath('changes.0', 'username')
            ->assertJsonPath('requires_reauthentication', true);

        $admin->refresh();
        $this->assertSame('ClinicAdmin', $admin->username);
        $this->assertSame($originalPasswordHash, $admin->password_hash);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $change->refresh();
        $this->assertNull($change->code_hash);
        $this->assertNotNull($change->confirmed_at);

        $this->postJson("/api/admin/security/credential-changes/{$change->id}/confirm", [
            'code' => $plainCode,
        ])->assertUnprocessable();
    }

    public function test_admin_cannot_change_staff_credentials(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', '09170001004', 'Admin');
        $staff = $this->createUser('staff', 'staff-placeholder@gmail.com', '09170001005');
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/security/staff/{$staff->user_id}/credential-change", [
            'username' => 'ClinicStaff',
            'password' => 'NewStaffPass!234',
            'password_confirmation' => 'NewStaffPass!234',
        ])->assertNotFound();

        $this->assertNull($staff->fresh()->username);
        $this->assertTrue(Hash::check('CurrentAdmin!234', $staff->fresh()->password_hash));
        $this->assertDatabaseCount('privileged_credential_changes', 0);
        Notification::assertNothingSent();
    }

    public function test_expired_change_does_not_modify_credentials(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', '09170001006', 'Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/security/account/credential-change', [
            'current_password' => 'CurrentAdmin!234',
            'password' => 'ChangedAdmin!234',
            'password_confirmation' => 'ChangedAdmin!234',
        ]);
        $response->assertAccepted();
        $plainCode = $this->credentialChangeCodeSentTo(
            $admin,
            'Admin password change',
            'Admin Bethlehem',
        );
        $change = PrivilegedCredentialChange::query()->findOrFail($response->json('change_id'));
        $change->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/api/admin/security/credential-changes/{$change->id}/confirm", [
            'code' => $plainCode,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('expired', true);

        $this->assertTrue(Hash::check('CurrentAdmin!234', $admin->fresh()->password_hash));
        $this->assertTrue(Hash::check($plainCode, $change->fresh()->code_hash));
    }

    public function test_security_code_can_be_resent_after_the_cooldown_and_replaces_the_old_code(): void
    {
        $this->freezeTime();
        Notification::fake();
        $admin = $this->createUser('admin', 'bethlehem.admin.test@gmail.com', '09170001008', 'Admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/security/account/credential-change', [
            'current_password' => 'CurrentAdmin!234',
            'password' => 'ChangedAdmin!234',
            'password_confirmation' => 'ChangedAdmin!234',
        ])->assertAccepted();
        $change = PrivilegedCredentialChange::query()->findOrFail($response->json('change_id'));
        $oldHash = $change->code_hash;
        $oldCode = $this->credentialChangeCodeSentTo($admin, 'Admin password change', 'Admin Bethlehem');

        $this->postJson("/api/admin/security/credential-changes/{$change->id}/resend")
            ->assertTooManyRequests()->assertHeader('Retry-After');

        $change->update(['last_sent_at' => now()->subMinutes(2)]);
        $this->postJson("/api/admin/security/credential-changes/{$change->id}/resend")
            ->assertOk()
            ->assertJsonPath('purpose', 'Admin password change')
            ->assertJsonPath('message', 'New code requested. Check your email.')
            ->assertJsonPath('resend_after', 600)->assertJsonStructure(['email_delivery_token']);

        $this->assertNotSame($oldHash, $change->fresh()->code_hash);
        Notification::assertSentToTimes(
            $admin,
            ConfirmPrivilegedCredentialChangeCodeNotification::class,
            2,
        );
        $newCode = $this->credentialChangeCodeSentTo($admin, 'Admin password change', 'Admin Bethlehem');
        $this->assertNotSame($oldCode, $newCode);
        $this->postJson("/api/admin/security/credential-changes/{$change->id}/confirm", ['code' => $oldCode])->assertUnprocessable();
        $this->postJson("/api/admin/security/credential-changes/{$change->id}/confirm", ['code' => $newCode])->assertOk();
        $this->postJson("/api/admin/security/credential-changes/{$change->id}/confirm", ['code' => $newCode])->assertUnprocessable();
    }

    public function test_staff_cannot_access_admin_security_management(): void
    {
        $staff = $this->createUser('staff', 'staff@gmail.com', '09170001007');
        Sanctum::actingAs($staff);

        $this->getJson('/api/admin/security/accounts')->assertForbidden();
    }

    public function test_staff_can_only_change_their_own_password_after_email_code_verification(): void
    {
        Notification::fake();
        $staff = $this->createUser('staff', 'staff@gmail.com', '09170001009', 'clinicstaff');
        $staff->update([
            'staff_type' => 'clinic',
            'staff_subrole' => 'veterinarian',
        ]);
        $staff->createToken('staff_token');
        Sanctum::actingAs($staff);

        $this->getJson('/api/settings/security/account')
            ->assertOk()
            ->assertJsonPath('account.user_id', $staff->user_id)
            ->assertJsonPath('account.staff_type', 'clinic')
            ->assertJsonPath('account.staff_subrole', 'veterinarian')
            ->assertJsonMissingPath('staff')
            ->assertJsonMissingPath('admin');

        $this->postJson('/api/settings/security/password-change', [
            'current_password' => 'wrong-password',
            'password' => 'ChangedStaff!234',
            'password_confirmation' => 'ChangedStaff!234',
        ])->assertUnprocessable();

        $this->postJson('/api/settings/security/password-change', [
            'current_password' => 'CurrentAdmin!234',
            'password' => 'ChangedStaff!234',
            'password_confirmation' => 'ChangedStaff!234',
            'username' => 'changedstaff',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/settings/security/password-change', [
            'current_password' => 'CurrentAdmin!234',
            'password' => 'ChangedStaff!234',
            'password_confirmation' => 'ChangedStaff!234',
        ])
            ->assertAccepted()
            ->assertJsonPath('purpose', 'Staff password change');

        $this->assertTrue(Hash::check('CurrentAdmin!234', $staff->fresh()->password_hash));
        $plainCode = $this->credentialChangeCodeSentTo(
            $staff,
            'Staff password change',
            'Staff Bethlehem',
        );
        $change = PrivilegedCredentialChange::query()->findOrFail($response->json('change_id'));

        // Earlier invalid password requests consumed this user's existing endpoint budget.
        $this->travel(601)->seconds();
        Notification::fake();
        $this->postJson("/api/settings/security/credential-changes/{$change->id}/resend")->assertOk();
        $newCode = $this->credentialChangeCodeSentTo($staff, 'Staff password change', 'Staff Bethlehem');
        $this->assertNotSame($plainCode, $newCode);
        $this->postJson("/api/settings/security/credential-changes/{$change->id}/confirm", ['code' => $plainCode])->assertUnprocessable();
        $this->postJson("/api/settings/security/credential-changes/{$change->id}/confirm", [
            'code' => $newCode,
        ])
            ->assertOk()
            ->assertJsonPath('changes.0', 'password')
            ->assertJsonPath('requires_reauthentication', false);

        $staff->refresh();
        $this->assertTrue(Hash::check('ChangedStaff!234', $staff->password_hash));
        $this->assertSame('clinicstaff', $staff->username);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_credential_mail_skips_replaced_consumed_and_expired_issuances(): void
    {
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
        $admin = $this->createUser('admin', 'admin@gmail.com', '09170001999', 'Admin');
        $service = app(\App\Services\PrivilegedCredentialChangeService::class);
        $payload = $service->request($admin, $admin, 'ChangedAdmin', null);
        $this->assertIsString($payload['email_delivery_token'] ?? null);
        $change = PrivilegedCredentialChange::query()->sole();
        $change->update(['failed_attempts' => 2]);
        $this->travel(61)->seconds();
        $resend = $service->resend($admin, $change);
        $this->assertSame(2, $change->fresh()->failed_attempts);
        $this->assertSame(60, $resend['resend_after']);
        $hash = $change->fresh()->code_hash;
        $this->assertSame('cooldown', $service->resend($admin, $change)['status']);
        $this->assertSame($hash, $change->fresh()->code_hash, 'A stale challenge instance cannot bypass resend locking or cooldown.');
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame('superseded', \App\Services\AuthenticationMailDelivery::status($payload['email_delivery_token']));
        $change->update(['confirmed_at' => now(), 'code_hash' => null]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $service->request($admin, $admin, 'AnotherAdmin', null);
        $this->travel(11)->minutes();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_credential_resend_queue_insert_failure_keeps_the_previous_issuance(): void
    {
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config()->set('queue.default', 'database');
        $admin = $this->createUser('admin', 'admin@gmail.com', '09170001999', 'Admin');
        $service = app(\App\Services\PrivilegedCredentialChangeService::class);
        $service->request($admin, $admin, 'ChangedAdmin', null);
        $change = PrivilegedCredentialChange::query()->sole();
        $before = $change->getAttributes();
        $this->travel(61)->seconds();
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER reject_auth_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        try {
            $service->resend($admin, $change);
            $this->fail('Expected queue failure.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($before, $change->fresh()->getAttributes());
            $this->assertDatabaseCount('jobs', 1);
        }
    }

    public function test_locked_credential_issuance_is_not_mailed(): void
    {
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config()->set('queue.default', 'database');
        $admin = $this->createUser('admin', 'admin@gmail.com', '09170001999', 'Admin');
        app(\App\Services\PrivilegedCredentialChangeService::class)->request($admin, $admin, 'ChangedAdmin', null);
        PrivilegedCredentialChange::query()->update(['failed_attempts' => 5]);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_immediate_credential_mail_uses_the_shared_durable_job_without_duplicate_sends(): void
    {
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config()->set('queue.default', 'database');
        config()->set('mail.authentication.delivery', 'immediate');
        $admin = $this->createUser('admin', 'admin@gmail.com', '09170001999', 'Admin');
        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/admin/security/account/credential-change', [
            'current_password' => 'CurrentAdmin!234', 'username' => 'ChangedAdmin',
        ])->assertAccepted();
        $receipt = $response->json('email_delivery_token');
        $this->assertSame('sent', \App\Services\AuthenticationMailDelivery::status($receipt));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 0);
    }

    private function credentialChangeCodeSentTo(
        User $admin,
        string $purpose,
        string $targetName,
    ): string
    {
        $plainCode = null;

        Notification::assertSentTo(
            $admin,
            ConfirmPrivilegedCredentialChangeCodeNotification::class,
            function (ConfirmPrivilegedCredentialChangeCodeNotification $notification) use (
                $admin,
                $purpose,
                $targetName,
                &$plainCode,
            ): bool {
                $message = $notification->toMail($admin);
                $this->assertSame($purpose, $notification->purposeLabel);
                $this->assertSame($targetName, $notification->targetName);
                $this->assertSame(
                    "Security code for {$purpose} - Bethlehem Animal Clinic",
                    $message->subject,
                );
                $mailText = implode(' ', $message->introLines);
                $this->assertStringContainsString($purpose, $mailText);
                $this->assertStringContainsString(
                    $notification->targetsAdmin
                        ? 'your administrator account'
                        : "the staff account for {$targetName}",
                    $mailText,
                );
                $this->assertStringContainsString(
                    'Your six-digit security code is:',
                    $mailText,
                );
                $this->assertStringContainsString("**{$notification->code}**", $mailText);
                $this->assertMatchesRegularExpression(
                    '/<strong\b[^>]*>'.preg_quote($notification->code, '/').'<\/strong>/',
                    (string) $message->render(),
                );
                $this->assertNull($message->actionUrl);
                $this->assertNull($message->actionText);
                $plainCode = $notification->code;

                return preg_match('/^\d{6}$/', $plainCode) === 1;
            },
        );

        return $plainCode;
    }

    public function test_staff_profile_names_save_without_password_or_email_code_and_preserve_session(): void
    {
        Notification::fake();
        foreach (['grooming_receptionist', 'clinic_receptionist', 'veterinarian'] as $index => $subrole) {
            $staff = $this->createUser('staff', "profile{$index}@gmail.com", "0917111100{$index}", "ProfileStaff{$index}");
            $staff->update(['staff_type' => $index === 0 ? 'grooming' : 'clinic', 'staff_subrole' => $subrole]);
            $token = $staff->createToken('staff_token');
            $before = $staff->fresh()->getAttributes();
            $permissions = $staff->clinicPermissions();
            $this->withToken($token->plainTextToken)->patchJson('/api/settings/security/profile', [
                'first_name' => '  MARÍA   JOSÉ ', 'last_name' => " O'NEILL-SANTOS ", 'username' => $staff->username,
            ])->assertOk()->assertJsonPath('account.first_name', 'María José')
                ->assertJsonPath('account.last_name', "O'Neill-Santos")
                ->assertJsonPath('user.user_id', $staff->user_id)
                ->assertJsonPath('user.staff_permissions', $staff->staffPermissions())
                ->assertJsonPath('sidebar_revision', $staff->fresh()->sidebarRevision());
            $staff->refresh();
            foreach (['user_id', 'email', 'role', 'staff_type', 'staff_subrole', 'password_hash', 'is_active', 'is_archived'] as $field) {
                $this->assertSame($before[$field], $staff->getAttributes()[$field]);
            }
            $this->assertSame($permissions, $staff->clinicPermissions());
            $this->assertSame(1, $staff->tokens()->count());
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/settings/security/account')->assertOk()->assertJsonPath('account.first_name', 'María José');
            $this->patchJson('/api/settings/security/profile', ['first_name' => 'McDonald', 'last_name' => 'de la Cruz', 'username' => $staff->username])
                ->assertOk()->assertJsonPath('account.first_name', 'McDonald')->assertJsonPath('account.last_name', 'de la Cruz');
            $this->app['auth']->forgetGuards();
        }
        Notification::assertNothingSent();
        $this->assertSame(0, PrivilegedCredentialChange::count());
    }

    public function test_staff_username_preflight_does_not_save_and_confirmation_is_atomic(): void
    {
        Notification::fake();
        $staff = $this->createUser('staff', 'profile@gmail.com', '09171111010', 'ProfileStaff');
        Sanctum::actingAs($staff);
        $profile = ['first_name' => 'Updated', 'last_name' => 'Person', 'username' => 'New.Staff'];
        $this->postJson('/api/settings/security/profile/validate', $profile)->assertOk();
        $this->assertSame('Staff', $staff->fresh()->first_name);
        $this->assertSame('ProfileStaff', $staff->fresh()->username);
        foreach ([null, 'wrong-password'] as $password) {
            $this->patchJson('/api/settings/security/profile', $profile + ['current_password' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('current_password');
            $this->assertSame('Staff', $staff->fresh()->first_name);
            $this->assertSame('ProfileStaff', $staff->fresh()->username);
        }
        $this->patchJson('/api/settings/security/profile', $profile + ['current_password' => 'CurrentAdmin!234'])
            ->assertOk()->assertJsonPath('account.username', 'New.Staff')->assertJsonPath('account.first_name', 'Updated');
        Notification::assertNothingSent();
        $this->assertSame(0, PrivilegedCredentialChange::count());
    }

    public function test_staff_profile_case_only_username_changes_need_password_but_unchanged_profile_is_a_noop(): void
    {
        $staff = $this->createUser('staff', 'profile@gmail.com', '09171111010', 'ProfileStaff');
        Sanctum::actingAs($staff);
        $profile = ['first_name' => 'Staff', 'last_name' => 'Bethlehem', 'username' => 'ProfileStaff'];
        $this->patchJson('/api/settings/security/profile', $profile)->assertOk();
        $profile['username'] = 'profilestaff';
        $this->patchJson('/api/settings/security/profile', $profile)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/settings/security/profile', $profile + ['current_password' => 'CurrentAdmin!234'])
            ->assertOk()->assertJsonPath('account.username', 'profilestaff');
    }

    public function test_staff_profile_rejects_case_insensitive_existing_and_pending_usernames_and_rechecks_on_save(): void
    {
        $staff = $this->createUser('staff', 'profile@gmail.com', '09171111010', 'ProfileStaff');
        Sanctum::actingAs($staff);
        $profile = ['first_name' => 'Updated', 'last_name' => 'Person', 'username' => 'ClaimedName'];
        $this->postJson('/api/settings/security/profile/validate', $profile)->assertOk();
        $this->createUser('customer', 'claimed@gmail.com', '09171111011', 'claimedname');
        $this->patchJson('/api/settings/security/profile', $profile + ['current_password' => 'CurrentAdmin!234'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame('Staff', $staff->fresh()->first_name);
        \App\Models\PendingCustomerRegistration::create([
            'first_name' => 'Pending', 'last_name' => 'Customer', 'username' => 'Pending.Name',
            'email' => 'pending@gmail.com', 'phone' => '09171111012', 'password_hash' => Hash::make('Customer!234'),
        ]);
        $profile['username'] = 'PENDING.NAME';
        $this->postJson('/api/settings/security/profile/validate', $profile)->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_staff_profile_honors_other_pending_staff_username_reservations(): void
    {
        Schema::create('pending_staff_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('email');
        });
        try {
            $staff = $this->createUser('staff', 'profile@gmail.com', '09171111010', 'ProfileStaff');
            Sanctum::actingAs($staff);
            \Illuminate\Support\Facades\DB::table('pending_staff_accounts')->insert(['username' => 'ReservedStaff', 'email' => 'invited@gmail.com']);
            $profile = ['first_name' => 'Updated', 'last_name' => 'Person', 'username' => 'reservedstaff'];
            $this->postJson('/api/settings/security/profile/validate', $profile)->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->patchJson('/api/settings/security/profile', $profile + ['current_password' => 'CurrentAdmin!234'])
                ->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->assertSame('Staff', $staff->fresh()->first_name);
        } finally {
            Schema::dropIfExists('pending_staff_accounts');
        }
    }

    public function test_staff_profile_username_race_returns_field_error_and_rolls_back_names(): void
    {
        $staff = $this->createUser('staff', 'profile@gmail.com', '09171111010', 'ProfileStaff');
        $other = $this->createUser('staff', 'other@gmail.com', '09171111011', 'OtherStaff');
        Sanctum::actingAs($staff);
        $raced = false;
        $event = 'eloquent.updating: '.User::class;
        \Illuminate\Support\Facades\Event::listen($event, function (User $saving) use ($staff, $other, &$raced): void {
            if ($saving->is($staff) && ! $raced) {
                $raced = true;
                // Exercise the real unique constraint after the availability check, as in staff setup tests.
                \Illuminate\Support\Facades\DB::table('users')->where('user_id', $other->getKey())->update(['username' => $saving->username]);
            }
        });
        try {
            $this->patchJson('/api/settings/security/profile', [
                'first_name' => 'Updated', 'last_name' => 'Person', 'username' => 'RacingName', 'current_password' => 'CurrentAdmin!234',
            ])->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->assertTrue($raced);
            $this->assertSame('ProfileStaff', $staff->fresh()->username);
            $this->assertSame('Staff', $staff->fresh()->first_name);
            $this->assertSame('Bethlehem', $staff->fresh()->last_name);
        } finally {
            \Illuminate\Support\Facades\Event::forget($event);
        }
    }

    public function test_staff_profile_rejects_invalid_and_protected_fields_including_target_ids(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $staff = $this->createUser('staff', 'profile@gmail.com', '09171111010', 'ProfileStaff');
        $other = $this->createUser('staff', 'other@gmail.com', '09171111011', 'OtherStaff');
        Sanctum::actingAs($staff);
        $profile = ['first_name' => 'Changed', 'last_name' => 'Person', 'username' => 'ProfileStaff'];
        foreach (['user_id', 'id', 'target_user_id', 'email', 'role', 'staff_type', 'staff_subrole', 'permissions', 'is_active', 'is_archived', 'password', 'password_hash', 'account_status', 'unexpected'] as $field) {
            $this->patchJson('/api/settings/security/profile', $profile + [$field => $other->user_id])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['1abc', 'ab', str_repeat('a', 51), 'bad space', 'abc@def'] as $username) {
            $this->postJson('/api/settings/security/profile/validate', array_replace($profile, ['username' => $username]))
                ->assertUnprocessable()->assertJsonValidationErrors('username');
        }
        foreach (['', '   ', str_repeat('x', 101), ['invalid'], 'Maria２', 'Gerald123', '---', "Jo\nSmith"] as $name) {
            $this->patchJson('/api/settings/security/profile', array_replace($profile, ['first_name' => $name]))
                ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        }
        $this->assertSame('Staff', $staff->fresh()->first_name);
        $this->assertSame('OtherStaff', $other->fresh()->username);
    }

    public function test_profile_endpoints_are_authenticated_staff_only(): void
    {
        $this->patchJson('/api/settings/security/profile', [])->assertUnauthorized();
        foreach (['admin', 'customer'] as $index => $role) {
            Sanctum::actingAs($this->createUser($role, "role{$index}@gmail.com", "0917111102{$index}", "RoleUser{$index}"));
            $this->patchJson('/api/settings/security/profile', [])->assertForbidden();
            $this->postJson('/api/settings/security/profile/validate', [])->assertForbidden();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('selfServiceEmailRoles')]
    public function test_active_staff_verify_both_addresses_before_email_and_sessions_change(string $type, string $subrole): void
    {
        Notification::fake();
        $staff = $this->createUser('staff', 'current@gmail.com', '09171111099', 'ExistingStaff');
        $staff->update(['staff_type' => $type, 'staff_subrole' => $subrole]);
        if ($subrole === 'grooming_receptionist') $staff->update(['email_verified_at' => null]);
        $original = $staff->refresh()->getAttributes();
        $token = $staff->createToken('staff')->plainTextToken;
        $staff->createToken('superseded')->accessToken->forceFill(['replaced_at' => now()])->save();
        $unrelated = $this->createUser('staff', 'unrelated@gmail.com', '09171111998', 'UnrelatedStaff');
        $unrelatedToken = $unrelated->createToken('other')->accessToken;
        $staff->tokens()->update(['last_interaction_at' => now()]);
        $this->withToken($token);
        $this->app['auth']->forgetGuards();
        $started = $this->postJson('/api/settings/security/email-change', [
            'email' => ' new@GMAIL.COM ', 'current_password' => 'CurrentAdmin!234',
        ])->assertAccepted()->assertJsonPath('pending_email_change.stage', 'current');
        $id = $started->json('pending_email_change.id');
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
        $this->getJson('/api/settings/security/account')->assertOk()->assertJsonPath('pending_email_change.id', $id)
            ->assertJsonMissingPath('pending_email_change.code_hash')->assertJsonMissingPath('pending_email_change.account_fingerprint')
            ->assertJsonMissingPath('pending_email_change.session_token_id');
        $currentCode = Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable, \App\Notifications\VerifyStaffEmailChangeNotification::class)->last()->code;
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $currentCode])
            ->assertOk()->assertJsonPath('pending_email_change.stage', 'new');
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
        $newNotification = Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable, \App\Notifications\VerifyStaffEmailChangeNotification::class)->last();
        $newCode = $newNotification->code;
        $this->assertNotSame($currentCode, $newCode);
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $currentCode])->assertUnprocessable();
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $newCode])
            ->assertOk()->assertJsonPath('completed', true)->assertJsonMissingPath('token');
        $staff->refresh();
        $this->assertSame('new@gmail.com', $staff->email);
        foreach (['user_id', 'first_name', 'last_name', 'username', 'password_hash', 'role', 'staff_type', 'staff_subrole', 'is_active'] as $field) {
            $this->assertSame($original[$field], $staff->getAttributes()[$field]);
        }
        $this->assertNotNull($staff->email_verified_at);
        $this->assertSame(0, $staff->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $unrelatedToken->getKey(), 'tokenable_id' => $unrelated->getKey()]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/settings/security/account')->assertUnauthorized();
    }

    public static function selfServiceEmailRoles(): array
    {
        return [['clinic', 'veterinarian'], ['clinic', 'clinic_receptionist'], ['grooming', 'grooming_receptionist']];
    }

    public function test_self_service_email_validation_ownership_and_role_restrictions(): void
    {
        [$staff, $token] = $this->emailStaffFixture();
        $this->createUser('customer', 'USED@gmail.com', '09171111100', 'Other');
        $service = app(\App\Services\StaffEmailChangeService::class);
        foreach (['current@gmail.com', 'used@gmail.com', 'bad@-example..com', str_repeat('x', 151).'@gmail.com'] as $email) {
            try { $service->start($staff, $email, 'CurrentAdmin!234'); $this->fail('Invalid email must be rejected.'); }
            catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('email', $e->errors()); }
        }
        $this->postJson('/api/settings/security/email-change', ['email' => 'new@gmail.com', 'current_password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $pending = $this->postJson('/api/settings/security/email-change', ['email' => 'new@gmail.com', 'current_password' => 'CurrentAdmin!234'])->assertAccepted()->json('pending_email_change');
        $other = $this->createUser('staff', 'other@gmail.com', '09171111101', 'OtherStaff');
        $this->useEmailToken($other->createToken('staff')->plainTextToken);
        $this->postJson('/api/settings/security/email-change/'.$pending['id'].'/confirm', ['stage' => 'current', 'code' => '123456'])->assertNotFound();
        $this->deleteJson('/api/settings/security/email-change/'.$pending['id'])->assertNotFound();
        foreach (['admin', 'customer'] as $index => $role) {
            $user = $this->createUser($role, $role.'@gmail.com', '0917111111'.$index);
            $this->useEmailToken($user->createToken('account')->plainTextToken);
            $this->postJson('/api/settings/security/email-change', ['email' => 'new@gmail.com', 'current_password' => 'CurrentAdmin!234'])->assertForbidden();
        }
        $this->useEmailToken($token);
        $this->postJson('/api/settings/security/email-change', ['email' => 'new@gmail.com', 'current_password' => 'CurrentAdmin!234', 'user_id' => $other->getKey()])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
    }

    public function test_email_resend_cancel_expiry_and_replacement_preserve_security_budgets(): void
    {
        $this->freezeTime();
        [$staff] = $this->emailStaffFixture();
        $pending = $this->startEmailChange();
        $id = $pending['id']; $old = $this->latestEmailCode();
        $this->postJson("/api/settings/security/email-change/{$id}/resend", ['stage' => 'current'])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson("/api/settings/security/email-change/{$id}/resend", ['stage' => 'current'])->assertOk();
        $this->assertNotSame($old, $this->latestEmailCode());
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $old])->assertUnprocessable();
        $this->deleteJson("/api/settings/security/email-change/{$id}")->assertOk();
        $this->getJson('/api/settings/security/account')->assertOk()->assertJsonPath('pending_email_change', null);
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertUnprocessable();
        $this->travel(61)->seconds();
        $replacement = $this->startEmailChange('replacement@gmail.com');
        $this->assertNotSame($id, $replacement['id']);
        $this->assertSame(4, $replacement['attempts_remaining']);
        $this->assertSame($pending['expires_at'], $replacement['expires_at']);
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $old])->assertNotFound();
        $this->travel(21)->minutes();
        $this->getJson('/api/settings/security/account')->assertOk()->assertJsonPath('pending_email_change', null);
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
    }

    public function test_email_codes_expire_and_lock_after_five_incorrect_attempts(): void
    {
        [$staff] = $this->emailStaffFixture();
        $pending = $this->startEmailChange(); $id = $pending['id'];
        \App\Models\StaffEmailChange::whereKey($id)->update(['code_expires_at' => now()->subSecond()]);
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertUnprocessable()->assertJsonPath('status', 'expired');
        $this->travel(61)->seconds();
        $this->postJson("/api/settings/security/email-change/{$id}/resend", ['stage' => 'current'])->assertOk();
        $wrong = $this->latestEmailCode() === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $wrong])
                ->assertUnprocessable()->assertJsonPath('status', $i === 4 ? 'locked' : 'invalid_code');
        }
        $this->deleteJson("/api/settings/security/email-change/{$id}")->assertOk();
        $this->travel(61)->seconds();
        $this->postJson('/api/settings/security/email-change', ['email' => 'replacement@gmail.com', 'current_password' => 'CurrentAdmin!234'])->assertUnprocessable()->assertJsonPath('status', 'locked');
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emailAuthorityChanges')]
    public function test_email_change_rechecks_mutable_account_and_session_authority(string $field, mixed $value): void
    {
        [$staff, $token] = $this->emailStaffFixture();
        $pending = $this->startEmailChange();
        if ($field === 'session') {
            app(\App\Services\AccountSessionService::class)->issue($staff);
        } else {
            User::whereKey($staff->getKey())->update([$field => $value]);
        }
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/settings/security/email-change/'.$pending['id'].'/confirm', ['stage' => 'current', 'code' => $this->latestEmailCode()]);
        $this->assertContains($response->status(), [401, 403, 422]);
        $this->assertNotSame('new@gmail.com', $staff->fresh()->email);
    }

    public static function emailAuthorityChanges(): array
    {
        return [['is_active', false], ['is_archived', true], ['role', 'admin'], ['staff_subrole', 'clinic_receptionist'],
            ['email', 'changed@gmail.com'], ['password_hash', 'changed-password'],
            ['password_hash', User::passwordSetupPlaceholder()], ['session', null]];
    }

    public function test_new_email_code_expiration_and_request_expiration_preserve_the_registered_email(): void
    {
        [$staff] = $this->emailStaffFixture();
        $id = $this->startEmailChange()['id'];
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertOk();
        $code = $this->latestEmailCode();
        \App\Models\StaffEmailChange::whereKey($id)->update(['code_expires_at' => now()->subSecond()]);
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $code])
            ->assertUnprocessable()->assertJsonPath('status', 'expired');
        \App\Models\StaffEmailChange::whereKey($id)->update(['code_expires_at' => now()->addMinute(), 'expires_at' => now()->subSecond()]);
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $code])
            ->assertUnprocessable()->assertJsonPath('status', 'invalid');
        $this->getJson('/api/settings/security/account')->assertOk()->assertJsonPath('pending_email_change', null);
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_email_queue_failure_preserves_current_stage_and_valid_code(): void
    {
        [$staff] = $this->emailStaffFixture();
        $pending = $this->startEmailChange(); $id = $pending['id']; $code = $this->latestEmailCode();
        $before = \App\Models\StaffEmailChange::findOrFail($id)->getAttributes();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('private mail failure'));
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $code])->assertStatus(503)->assertDontSee('private mail failure');
        $this->assertSame($before, \App\Models\StaffEmailChange::findOrFail($id)->getAttributes());
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
    }

    public function test_email_final_claim_conflict_preserves_account_and_sessions(): void
    {
        [$staff] = $this->emailStaffFixture();
        $pending = $this->startEmailChange(); $id = $pending['id'];
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertOk();
        $this->createUser('staff', 'new@gmail.com', '09171111200', 'Claimant');
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $this->latestEmailCode()])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_email_change_durable_queue_supersession_delivery_rollback_and_final_notice_failure(): void
    {
        [$staff] = $this->emailStaffFixture();
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        Notification::swap(new \Illuminate\Notifications\ChannelManager($this->app));
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER reject_initial_email_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $this->postJson('/api/settings/security/email-change', ['email' => 'new@gmail.com', 'current_password' => 'CurrentAdmin!234'])->assertStatus(503);
        $this->assertDatabaseCount('staff_email_changes', 0);
        $this->assertDatabaseCount('jobs', 0);
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER reject_initial_email_job');
        $pending = $this->startEmailChange(); $id = $pending['id'];
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(\Illuminate\Support\Facades\DB::table('jobs')->value('payload'), true);
        $this->assertStringNotContainsString('VerifyStaffEmailChangeNotification', $payload['data']['command']);
        $this->travel(61)->seconds();
        $this->postJson("/api/settings/security/email-change/{$id}/resend", ['stage' => 'current'])->assertOk();
        $this->deleteJson("/api/settings/security/email-change/{$id}")->assertOk();
        $this->travel(61)->seconds();
        $id = $this->startEmailChange()['id'];
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages, 'Cancelled and superseded codes must not be delivered.');
        $mail = $messages[0]->getOriginalMessage();
        $this->assertSame('current@gmail.com', $mail->getTo()[0]->getAddress());
        preg_match('/\b[0-9]{6}\b/', $mail->getTextBody(), $digits);
        $currentState = \App\Models\StaffEmailChange::findOrFail($id)->getAttributes();
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER reject_transition_email_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $digits[0]])->assertStatus(503);
        $this->assertSame($currentState, \App\Models\StaffEmailChange::findOrFail($id)->getAttributes());
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER reject_transition_email_job');
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $digits[0]])->assertOk();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        $mail = $messages[1]->getOriginalMessage();
        $this->assertSame('new@gmail.com', $mail->getTo()[0]->getAddress());
        preg_match('/\b[0-9]{6}\b/', $mail->getTextBody(), $digits);
        $before = \App\Models\StaffEmailChange::findOrFail($id)->getAttributes();
        $this->travel(61)->seconds();
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER reject_email_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");
        $this->postJson("/api/settings/security/email-change/{$id}/resend", ['stage' => 'new'])->assertStatus(503);
        $this->assertSame($before, \App\Models\StaffEmailChange::findOrFail($id)->getAttributes());
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $digits[0]])->assertOk()->assertJsonPath('completed', true);
        $this->assertSame('new@gmail.com', $staff->fresh()->email);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_completed_email_change_uses_new_mailbox_for_login_and_recovery_only(): void
    {
        [$staff] = $this->emailStaffFixture();
        $pending = $this->startEmailChange(); $id = $pending['id'];
        $this->postJson('/api/sign-in', ['identifier' => 'new@gmail.com', 'password' => 'CurrentAdmin!234'])->assertUnauthorized();
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertOk();
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $this->latestEmailCode()])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/sign-in', ['identifier' => 'current@gmail.com', 'password' => 'CurrentAdmin!234'])->assertUnauthorized();
        $this->postJson('/api/sign-in', ['identifier' => 'new@gmail.com', 'password' => 'CurrentAdmin!234'])->assertAccepted();
        Notification::assertSentTo($staff, \App\Notifications\ConfirmLoginNotification::class,
            fn ($notification, $channels, $recipient) => $recipient->email === 'new@gmail.com');
        $service = app(\App\Services\PasswordResetService::class);
        $service->sendCode('current@gmail.com');
        Notification::assertNotSentTo($staff, \App\Notifications\PasswordResetCodeNotification::class);
        $this->travel(61)->seconds();
        $service->sendCode('new@gmail.com');
        Notification::assertSentTo($staff, \App\Notifications\PasswordResetCodeNotification::class,
            fn ($notification, $channels, $recipient) => $recipient->email === 'new@gmail.com');
    }

    public function test_email_change_migration_is_reversible_without_mutating_user_data(): void
    {
        [$staff] = $this->emailStaffFixture();
        $before = $staff->refresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_25_000002_create_staff_email_changes.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('staff_email_changes'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('staff_email_changes'));
        $this->assertSame($before, $staff->fresh()->getAttributes());
    }

    public function test_email_change_retries_a_deadlock_without_partial_mutation_or_duplicate_notice(): void
    {
        [$staff] = $this->emailStaffFixture();
        $pending = $this->startEmailChange(); $id = $pending['id'];
        $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertOk();
        $code = $this->latestEmailCode();
        $event = 'eloquent.updating: '.User::class;
        $attempts = 0;
        \Illuminate\Support\Facades\Event::listen($event, function (User $saving) use ($staff, &$attempts): void {
            if ($saving->getKey() === $staff->getKey() && $saving->isDirty('email') && ++$attempts === 1) {
                throw new \Illuminate\Database\DeadlockException('Deadlock found when trying to get lock');
            }
        });
        try {
            $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'new', 'code' => $code])->assertOk()->assertJsonPath('completed', true);
            $this->assertSame(2, $attempts);
            $this->assertSame('new@gmail.com', $staff->fresh()->email);
            Notification::assertSentOnDemandTimes(\App\Notifications\StaffEmailChangedNotification::class, 1);
        } finally { \Illuminate\Support\Facades\Event::forget($event); }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overlappingEmailChanges')]
    public function test_overlapping_email_operations_keep_one_authoritative_result(string $oneOp, string $twoOp, string $oneStatus, string $twoStatus, string $email): void
    {
        [$staff] = $this->emailStaffFixture();
        $id = $code = '';
        if ($oneOp !== 'start') {
            $id = $this->startEmailChange()['id'];
            $code = $this->latestEmailCode();
            if ($oneOp !== 'current') {
                $this->postJson("/api/settings/security/email-change/{$id}/confirm", ['stage' => 'current', 'code' => $code])->assertOk();
                $code = $this->latestEmailCode();
            }
            \App\Models\StaffEmailChange::whereKey($id)->update(['last_sent_at' => now()->subMinutes(2)]);
        }
        $grant = str_repeat('g', 64);
        \App\Models\PasswordResetRequest::create(['user_id' => $staff->getKey(), 'token_hash' => Hash::make('123456'),
            'verified_token_hash' => hash('sha256', $grant), 'expires_at' => now()->addMinutes(15), 'last_sent_at' => now()]);
        $secondUser = $staff;
        $secondId = $id; $secondCode = $code;
        if ($twoOp === 'claim') {
            $secondUser = $this->createUser('staff', 'other@gmail.com', '09171111999', 'ClaimingStaff');
            $secondUser->update(['staff_type' => 'grooming', 'staff_subrole' => 'grooming_receptionist']);
            $this->useEmailToken($secondUser->createToken('staff')->plainTextToken);
            $secondId = $this->startEmailChange()['id'];
            $this->postJson("/api/settings/security/email-change/{$secondId}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertOk();
            $secondCode = $this->latestEmailCode();
        }
        $race = $this->runEmailRace(
            [$oneOp, $staff->getKey(), $staff->tokens()->firstOrFail()->getKey(), $id, $code, $grant],
            [$twoOp, $secondUser->getKey(), $secondUser->tokens()->firstOrFail()->getKey(), $secondId, $secondCode, $grant],
            $staff->getKey(),
        );
        if ($twoOp === 'claim') {
            $statuses = [$race['one'], $race['two']]; sort($statuses);
            $this->assertSame(['completed', 'email_unavailable'], $statuses);
            $email = $race['one'] === 'completed' ? 'new@gmail.com' : 'current@gmail.com';
        } else {
            $this->assertSame($oneStatus, $race['one']); $this->assertSame($twoStatus, $race['two']);
        }
        $this->assertSame($email, $race['email']);
        $this->assertLessThanOrEqual(1, $race['claims']);
    }

    private function runEmailRace(array $firstArguments, array $secondArguments, int $lockUserId): array
    {
        $directory = sys_get_temp_dir().'/bethlehem-email-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $isMysql = \Illuminate\Support\Facades\DB::getDriverName() === 'mysql';
        $database = $isMysql ? config('database.connections.mysql.database') : $directory.'/email.sqlite';
        $first = $second = $connection = null;
        try {
            if (! $isMysql) \Illuminate\Support\Facades\DB::statement('VACUUM INTO '.\Illuminate\Support\Facades\DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                [$script, $database, $directory, $position, $operation, $userId, $tokenId, $changeId, $code, $grant, $lockUserId] = $argv;
                $directory = realpath($directory);
                $isMysql = getenv('DB_CONNECTION') === 'mysql';
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-email-[a-f0-9]{12}\z/', basename($directory))
                    || ($isMysql ? !preg_match('/\Abethlehem_email_test_[a-f0-9]{12}\z/', $database)
                        : (basename($database) !== 'email.sqlite' || !is_file($database)))) throw new RuntimeException('Disposable email test database required.');
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $driver = $isMysql ? 'mysql' : 'sqlite';
                config(['database.default' => $driver, 'database.connections.'.$driver.'.database' => $database,
                    'database.connections.'.$driver.'.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
                if ($isMysql && !in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('Local test server required.');
                Illuminate\Support\Facades\DB::purge($driver);
                $connection = Illuminate\Support\Facades\DB::connection();
                if ($isMysql ? $connection->selectOne('SELECT DATABASE() AS db')->db !== $database
                    : realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) throw new RuntimeException('Unexpected database.');
                Illuminate\Support\Facades\Notification::fake();
                $user = App\Models\User::findOrFail($userId);
                $user->withAccessToken(Laravel\Sanctum\PersonalAccessToken::findOrFail($tokenId));
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Email test barrier timed out.');
                };
                $busy = $probed = false;
                $connection->beforeStartingTransaction(function () use ($position, $directory, $database, $wait, $isMysql, $lockUserId, &$busy, &$probed) {
                    if ($position !== 'second' || $probed) return;
                    $probed = true;
                    $wait($directory.'/first-lock');
                    if ($isMysql) {
                        $cfg = config('database.connections.mysql');
                        $probe = new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$database, $cfg['username'], $cfg['password']);
                        $probe->exec('SET SESSION innodb_lock_wait_timeout=1');
                        $probe->beginTransaction();
                        try { $probe->query('SELECT user_id FROM users WHERE user_id = '.(int) $lockUserId.' FOR UPDATE'); }
                        catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 1205) throw $e; $busy = true; }
                        $probe->rollBack();
                    } else {
                        $probe = new PDO('sqlite:'.$database); $probe->exec('PRAGMA busy_timeout=0');
                        try { $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK'); }
                        catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 5) throw $e; $busy = true; }
                    }
                    touch($directory.'/second-attempt');
                });
                $app['events']->listen(Illuminate\Database\Events\TransactionBeginning::class,
                    function () use ($position, $directory, $wait, $isMysql) {
                        if ($position === 'first' && !$isMysql) { touch($directory.'/first-lock'); $wait($directory.'/second-attempt'); }
                    });
                $held = false;
                $connection->listen(function ($event) use ($position, $directory, $wait, $isMysql, &$held) {
                    if ($isMysql && $position === 'first' && !$held && str_contains($event->sql, 'users') && str_contains(strtolower($event->sql), 'for update')) {
                        $held = true; touch($directory.'/first-lock'); $wait($directory.'/second-attempt');
                    }
                });
                $service = app(App\Services\StaffEmailChangeService::class);
                try {
                    $result = match ($operation) {
                        'recover-start' => app(App\Services\StaffEmailRecoveryService::class)->start($user, (int) $lockUserId, ['email' => $position.'@gmail.com', 'current_password' => 'CurrentAdmin!234', 'reason' => 'inaccessible', 'identity_verified' => true, 'identity_method' => 'in_person_employment_record']),
                        'recover-approve' => app(App\Services\StaffEmailRecoveryService::class)->confirm($user, (int) $lockUserId, $changeId, $code),
                        'recover-final' => app(App\Services\StaffEmailRecoveryService::class)->complete($code, 'CurrentAdmin!234'),
                        'recover-resend' => app(App\Services\StaffEmailRecoveryService::class)->resendLink($user, (int) $lockUserId, $changeId, 'CurrentAdmin!234'),
                        'role' => Illuminate\Support\Facades\DB::transaction(function () use ($lockUserId) {
                            $target = App\Models\User::whereKey($lockUserId)->lockForUpdate()->firstOrFail();
                            $target->update(['staff_subrole' => 'clinic_receptionist']); return ['status' => 'role'];
                        }),
                        'start' => $service->start($user, $position.'@gmail.com', 'CurrentAdmin!234'),
                        'current' => $service->confirm($user, $changeId, 'current', $code),
                        'final', 'claim' => $service->confirm($user, $changeId, 'new', $code),
                        'resend' => $service->resend($user, $changeId, 'new'),
                        'reset' => app(App\Services\PasswordResetService::class)->reset($grant, 'ResetPassword!234'),
                    };
                    $status = $result['status'];
                } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $status = 'http'.$e->getStatusCode(); }
                catch (Illuminate\Validation\ValidationException $e) { $status = isset($e->errors()['email']) ? 'email_unavailable' : 'invalid'; }
                echo json_encode(['pid' => getmypid(), 'busy' => $busy, 'status' => $status]);
                PHP;
            $env = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused.php', 'DB_CONNECTION' => $isMysql ? 'mysql' : 'sqlite',
                'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'AUTH_MAIL_DELIVERY' => 'queued'];
            $first = new \Symfony\Component\Process\Process(array_merge([PHP_BINARY, '-r', $worker, $database, $directory, 'first'], $firstArguments, [$lockUserId]), base_path(), $env);
            $second = new \Symfony\Component\Process\Process(array_merge([PHP_BINARY, '-r', $worker, $database, $directory, 'second'], $secondArguments, [$lockUserId]), base_path(), $env);
            $first->setTimeout(15); $second->setTimeout(15); $first->start(); $second->start(); $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($one['pid'], $two['pid']);
            $this->assertTrue($two['busy'], 'The operations must overlap a real writer lock.');
            $connection = $isMysql ? \Illuminate\Support\Facades\DB::getPdo() : new \PDO('sqlite:'.$database);
            return ['one' => $one['status'], 'two' => $two['status'],
                'email' => $connection->query('SELECT email FROM users WHERE user_id = '.(int) $lockUserId)->fetchColumn(),
                'hold' => (bool) $connection->query('SELECT email_recovery_id FROM users WHERE user_id = '.(int) $lockUserId)->fetchColumn(),
                'claims' => (int) $connection->query("SELECT COUNT(*) FROM users WHERE email='new@gmail.com'")->fetchColumn(),
                'active_recoveries' => (int) $connection->query('SELECT COUNT(*) FROM staff_email_recoveries WHERE active_user_id = '.(int) $lockUserId)->fetchColumn()];
        } finally {
            $first?->stop(); $second?->stop(); $connection = null;
            foreach (['email.sqlite', 'email.sqlite-journal', 'email.sqlite-wal', 'email.sqlite-shm', 'first-lock', 'second-attempt'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overlappingRecoveries')]
    public function test_overlapping_recovery_operations_serialize_identity_changes(string $oneOp, string $twoOp, string $oneStatus, string $twoStatus, string $email, bool $hold): void
    {
        [$admin, $staff, $adminToken] = $this->recoveryFixture();
        $staffToken = $staff->createToken('staff');
        $staffTokenId = $staffToken->accessToken->getKey();
        $selfId = $selfCode = $id = $code = $plain = '';
        if (in_array('final', [$oneOp, $twoOp], true)) {
            $this->useEmailToken($staffToken->plainTextToken);
            $selfId = $this->startEmailChange()['id'];
            $this->postJson("/api/settings/security/email-change/{$selfId}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertOk();
            $selfCode = $this->latestEmailCode();
            $this->useEmailToken($adminToken);
        }
        if ($oneOp !== 'recover-start') {
            $id = $this->startRecovery($staff)['id']; $code = $this->latestRecoveryCode();
            if (array_intersect(['recover-final', 'recover-resend', 'role'], [$oneOp, $twoOp])) {
                $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery/{$id}/confirm", ['code' => $code])->assertOk();
                $plain = $this->latestRecoveryToken();
                \App\Models\StaffEmailRecovery::whereKey($id)->update(['last_sent_at' => now()->subMinutes(2)]);
            }
        }
        $grant = str_repeat('g', 64);
        \App\Models\PasswordResetRequest::create(['user_id' => $staff->getKey(), 'token_hash' => Hash::make('123456'),
            'verified_token_hash' => hash('sha256', $grant), 'expires_at' => now()->addMinutes(15), 'last_sent_at' => now()]);
        $claimArguments = [];
        if ($twoOp === 'claim') {
            $claimant = $this->createUser('staff', 'claimant@gmail.com', '09172222999', 'ClaimingStaff');
            $claimant->update(['staff_type' => 'grooming', 'staff_subrole' => 'grooming_receptionist']);
            $this->useEmailToken($claimant->createToken('staff')->plainTextToken);
            $claimId = $this->startEmailChange()['id'];
            $this->postJson("/api/settings/security/email-change/{$claimId}/confirm", ['stage' => 'current', 'code' => $this->latestEmailCode()])->assertOk();
            $claimArguments = ['claim', $claimant->getKey(), $claimant->tokens()->firstOrFail()->getKey(), $claimId, $this->latestEmailCode(), $grant];
            $this->useEmailToken($adminToken);
        }
        $arguments = function (string $operation) use ($admin, $staff, $staffTokenId, $selfId, $selfCode, $id, $code, $plain, $grant, $claimArguments): array {
            if ($operation === 'claim') return $claimArguments;
            return $operation === 'final'
                ? [$operation, $staff->getKey(), $staffTokenId, $selfId, $selfCode, $grant]
                : [$operation, $admin->getKey(), $admin->tokens()->firstOrFail()->getKey(), $id, $operation === 'recover-approve' ? $code : $plain, $grant];
        };
        $race = $this->runEmailRace($arguments($oneOp), $arguments($twoOp), $staff->getKey());
        if ($twoOp === 'claim') {
            $statuses = [$race['one'], $race['two']]; sort($statuses);
            $this->assertSame(['completed', 'email_unavailable'], $statuses);
            $email = $race['one'] === 'completed' ? 'new@gmail.com' : 'current@gmail.com';
            $hold = $race['one'] !== 'completed';
        } else {
            $this->assertSame($oneStatus, $race['one']);
            $this->assertSame($twoStatus, $race['two']);
        }
        $this->assertSame($email, $race['email']);
        $this->assertSame($hold, $race['hold']);
        $this->assertLessThanOrEqual(1, $race['active_recoveries']);
        $this->assertLessThanOrEqual(1, $race['claims']);
    }

    public static function overlappingRecoveries(): array
    {
        return [
            ['recover-start', 'recover-start', 'authorizing', 'cooldown', 'current@gmail.com', false],
            ['recover-approve', 'recover-approve', 'pending', 'invalid', 'current@gmail.com', true],
            ['recover-final', 'recover-final', 'completed', 'invalid', 'new@gmail.com', false],
            ['recover-resend', 'recover-final', 'pending', 'invalid', 'current@gmail.com', true],
            ['recover-final', 'recover-resend', 'completed', 'invalid', 'new@gmail.com', false],
            ['recover-final', 'claim', 'completed', 'email_unavailable', 'new@gmail.com', false],
            ['role', 'recover-final', 'role', 'invalid', 'current@gmail.com', true],
            ['recover-final', 'role', 'completed', 'role', 'new@gmail.com', false],
            ['recover-approve', 'reset', 'pending', 'invalid', 'current@gmail.com', true],
            ['reset', 'recover-approve', 'reset', 'invalid', 'current@gmail.com', false],
            ['recover-approve', 'final', 'pending', 'http403', 'current@gmail.com', true],
            ['final', 'recover-approve', 'completed', 'invalid', 'new@gmail.com', false],
        ];
    }

    public static function overlappingEmailChanges(): array
    {
        return [
            ['start', 'start', 'pending', 'cooldown', 'current@gmail.com'],
            ['current', 'current', 'pending', 'stale_stage', 'current@gmail.com'],
            ['final', 'final', 'completed', 'http401', 'new@gmail.com'],
            ['resend', 'final', 'pending', 'invalid_code', 'current@gmail.com'],
            ['final', 'resend', 'completed', 'http401', 'new@gmail.com'],
            ['final', 'reset', 'completed', 'invalid', 'new@gmail.com'],
            ['reset', 'final', 'reset', 'http401', 'current@gmail.com'],
            ['final', 'claim', 'completed', 'email_unavailable', 'new@gmail.com'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('selfServiceEmailRoles')]
    public function test_admin_recovery_preserves_the_staff_identity_and_requires_both_proofs(string $type, string $subrole): void
    {
        [$admin, $staff, $token] = $this->recoveryFixture($type, $subrole);
        $identity = $staff->getAttributes();
        unset($identity['email'], $identity['email_verified_at'], $identity['email_recovery_id']);
        $staffToken = $staff->createToken('staff')->plainTextToken;
        $request = $this->startRecovery($staff);
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        $code = $this->latestRecoveryCode();
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}/confirm", ['code' => $code])
            ->assertOk()->assertJsonPath('recovery.status', 'pending');
        $this->assertTrue($staff->fresh()->hasEmailRecoveryHold());
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
        $this->assertSame(0, $staff->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count(), 'Recovery must preserve the unrelated administrator session.');
        $link = Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable, \App\Notifications\StaffEmailRecoveryLinkNotification::class)->last();
        $this->assertNotNull($link);
        $plain = substr($link->recoveryUrl, strpos($link->recoveryUrl, '#') + 1);
        $this->assertStringNotContainsString($plain, json_encode($request));
        $this->postJson('/api/staff/email-recovery/verify', ['token' => $plain])->assertOk()->assertJsonPath('email', 'new@gmail.com');
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $plain, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/staff/email-recovery/complete', ['token' => str_repeat('a', 64), 'current_password' => 'CurrentAdmin!234'])->assertUnprocessable();
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $plain, 'current_password' => 'CurrentAdmin!234'])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonMissingPath('token');
        $this->assertSame($identity, $staff->fresh()->only(array_keys($identity)));
        $this->assertSame('new@gmail.com', $staff->fresh()->email);
        $this->assertNotNull($staff->fresh()->email_verified_at);
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        $this->assertSame(0, $staff->tokens()->count());
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $plain, 'current_password' => 'CurrentAdmin!234'])->assertUnprocessable();
        $this->assertDatabaseHas('staff_email_recovery_events', ['recovery_id' => $request['id'], 'event' => 'completed', 'actor_user_id' => $staff->user_id]);
        $this->assertDatabaseHas('staff_email_recovery_events', ['recovery_id' => $request['id'], 'event' => 'authorized', 'actor_user_id' => $admin->user_id]);
        $this->postJson('/api/sign-in', ['identifier' => 'current@gmail.com', 'password' => 'CurrentAdmin!234'])->assertUnauthorized();
        $this->postJson('/api/sign-in', ['identifier' => 'new@gmail.com', 'password' => 'CurrentAdmin!234'])->assertAccepted();
        Notification::assertSentTo($staff, \App\Notifications\ConfirmLoginNotification::class, fn ($notice, $channels, $recipient) => $recipient->email === 'new@gmail.com');
        app(\App\Services\PasswordResetService::class)->sendCode('current@gmail.com');
        Notification::assertNotSentTo($staff, \App\Notifications\PasswordResetCodeNotification::class);
        $this->travel(61)->seconds();
        app(\App\Services\PasswordResetService::class)->sendCode('new@gmail.com');
        Notification::assertSentTo($staff, \App\Notifications\PasswordResetCodeNotification::class, fn ($notice, $channels, $recipient) => $recipient->email === 'new@gmail.com');
    }

    public function test_recovery_hold_blocks_old_mailbox_challenges_sessions_resets_and_self_service(): void
    {
        [$admin, $staff, $adminToken] = $this->recoveryFixture();
        $staffToken = $staff->createToken('staff')->plainTextToken;
        $login = app(\App\Services\LoginEmailChallengeService::class)->send($staff, false);
        $staleChallenge = \App\Models\LoginEmailChallenge::where('user_id', $staff->getKey())->firstOrFail()->replicate();
        $loginCode = Notification::sent($staff, \App\Notifications\ConfirmLoginNotification::class)->last()->code;
        $this->useEmailToken($staffToken);
        $change = $this->startEmailChange('self@gmail.com');
        $this->useEmailToken($adminToken);
        [$recovery, $plain] = $this->authorizeRecovery($staff);
        $this->assertDatabaseCount('staff_email_changes', 0);
        $this->assertDatabaseCount('login_email_challenges', 0);
        $this->postJson('/api/sign-in', ['identifier' => 'current@gmail.com', 'password' => 'CurrentAdmin!234'])->assertForbidden();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $login['poll_token'], 'code' => $loginCode])->assertUnprocessable();
        $staleChallenge->save();
        $this->postJson('/api/email/login/confirm', ['poll_token' => $login['poll_token'], 'code' => $loginCode])->assertForbidden();
        $this->travel(61)->seconds();
        app(\App\Services\PasswordResetService::class)->sendCode('current@gmail.com');
        Notification::assertNotSentTo($staff, \App\Notifications\PasswordResetCodeNotification::class);
        try { app(\App\Services\AccountSessionService::class)->issue($staff); $this->fail('Hold allowed session issuance.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->useEmailToken($staffToken);
        $this->postJson('/api/settings/security/email-change', ['email' => 'other@gmail.com', 'current_password' => 'CurrentAdmin!234'])->assertUnauthorized();
        $injected = $staff->createToken('stale-session')->plainTextToken;
        $this->useEmailToken($injected);
        $this->getJson('/api/settings/security/account')->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->useEmailToken($adminToken);
        $this->getJson('/api/admin/security/accounts')->assertOk()->assertJsonPath('staff.0.is_active', true)->assertJsonPath('staff.0.email_recovery.status', 'pending');
        $this->travel(31)->minutes();
        $this->postJson('/api/staff/email-recovery/verify', ['token' => $plain])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->hasEmailRecoveryHold());
        $this->assertDatabaseHas('staff_email_recovery_events', ['recovery_id' => $recovery['id'], 'event' => 'expired']);
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $plain, 'current_password' => 'CurrentAdmin!234'])->assertUnprocessable();
    }

    public function test_recovery_hold_survives_admin_deactivation_and_reactivation(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        [$request] = $this->authorizeRecovery($staff);
        foreach ([false, true] as $active) {
            $this->patchJson("/api/admin/security/staff/{$staff->user_id}/status", ['active' => $active, 'current_password' => 'CurrentAdmin!234'])
                ->assertOk()->assertJsonPath('staff.email_recovery.id', $request['id']);
            $this->assertTrue($staff->fresh()->hasEmailRecoveryHold());
            $this->postJson('/api/sign-in', ['identifier' => $staff->email, 'password' => 'CurrentAdmin!234'])->assertForbidden();
        }
    }

    public function test_recovery_requires_admin_password_attestation_unique_email_and_eligible_staff(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$admin, $staff, $adminToken] = $this->recoveryFixture();
        $base = ['email' => 'new@gmail.com', 'current_password' => 'CurrentAdmin!234', 'reason' => 'inaccessible', 'identity_verified' => true, 'identity_method' => 'in_person_employment_record'];
        $url = "/api/admin/security/staff/{$staff->user_id}/email-recovery";
        foreach ([['current_password' => 'wrong'], ['identity_verified' => false], ['identity_method' => 'trivia'], ['email' => 'invalid'], ['email' => $admin->email], ['email' => $staff->email], ['reason' => 'other'], ['role' => 'admin']] as $invalid) {
            $this->postJson($url, array_merge($base, $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('staff_email_recoveries', 0);
        $staff->update(['is_active' => false]);
        $this->postJson($url, $base)->assertUnprocessable();
        $staff->update(['is_active' => true, 'is_archived' => true]);
        $this->postJson($url, $base)->assertUnprocessable();
        $staff->update(['is_archived' => false, 'is_active' => false, 'password_hash' => User::passwordSetupPlaceholder()]);
        $this->postJson($url, $base)->assertUnprocessable();
        $staff->update(['is_active' => true, 'password_hash' => Hash::make('CurrentAdmin!234')]);
        $this->useEmailToken($staff->createToken('staff')->plainTextToken);
        $this->postJson($url, $base)->assertForbidden();
        $customer = $this->createUser('customer', 'customer@gmail.com', '09172222003');
        $this->useEmailToken($customer->createToken('customer')->plainTextToken);
        $this->postJson($url, $base)->assertForbidden();
        $this->getJson($url.'/history')->assertForbidden();
    }

    public function test_recovery_admin_challenge_is_bound_to_snapshot_session_and_target(): void
    {
        [$admin, $staff, $token] = $this->recoveryFixture();
        $request = $this->startRecovery($staff);
        $code = $this->latestRecoveryCode();
        $url = "/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}";
        $this->postJson($url.'/confirm', ['code' => $code === '999999' ? '888888' : '999999'])->assertUnprocessable()->assertJsonPath('status', 'invalid_code');
        $this->postJson($url.'/confirm', ['code' => $code, 'email' => 'forged@gmail.com'])->assertUnprocessable();
        $other = $this->createUser('staff', 'other@gmail.com', '09172222004');
        $this->postJson("/api/admin/security/staff/{$other->user_id}/email-recovery/{$request['id']}/confirm", ['code' => $code])->assertNotFound();
        $this->useEmailToken($admin->createToken('another')->plainTextToken);
        $this->postJson($url.'/confirm', ['code' => $code])->assertUnprocessable();
        $this->useEmailToken($token);
        $staff->update(['staff_subrole' => 'clinic_receptionist']);
        $this->postJson($url.'/confirm', ['code' => $code])->assertUnprocessable();
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        Notification::assertSentOnDemandTimes(\App\Notifications\StaffEmailRecoveryLinkNotification::class, 0);
    }

    public function test_recovery_resend_and_cancellation_rotate_links_and_preserve_attempt_budget(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        [$request, $first] = $this->authorizeRecovery($staff, ['reason' => 'compromised']);
        $url = "/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}";
        $password = ['current_password' => 'CurrentAdmin!234'];
        $this->postJson($url.'/cancel', ['old_email_safe' => false])->assertOk()->assertJsonPath('status', 'pending');
        $this->assertTrue($staff->fresh()->hasEmailRecoveryHold(), 'Dismissing a stale authorization view cannot release an approved hold.');
        $this->postJson($url.'/resend', $password)->assertStatus(429);
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $first, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->postJson($url.'/resend', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson($url.'/resend', $password)->assertOk();
        $second = $this->latestRecoveryToken();
        $this->assertNotSame($first, $second);
        $this->assertSame(1, \App\Models\StaffEmailRecovery::find($request['id'])->failed_attempts);
        $this->postJson('/api/staff/email-recovery/verify', ['token' => $first])->assertUnprocessable();
        $this->postJson($url.'/cancel', [...$password, 'old_email_safe' => false])->assertUnprocessable()->assertJsonValidationErrors('old_email_safe');
        $this->assertTrue($staff->fresh()->hasEmailRecoveryHold());
        $staff->update(['is_active' => false]);
        $this->postJson($url.'/cancel', [...$password, 'old_email_safe' => true])->assertOk();
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        $this->assertFalse($staff->fresh()->is_active);
        $this->postJson('/api/staff/email-recovery/verify', ['token' => $second])->assertUnprocessable();
        $this->assertDatabaseCount('staff_email_recoveries', 1);
        $this->getJson("/api/admin/security/staff/{$staff->user_id}/email-recovery/history")->assertOk()->assertJsonPath('history.0.status', 'cancelled');
    }

    public function test_recovery_admin_code_expiry_resend_attempts_and_cancel_cannot_reset_budget(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        $request = $this->startRecovery($staff); $code = $this->latestRecoveryCode();
        $url = "/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}";
        $this->postJson($url.'/code')->assertStatus(429);
        $wrong = $code === '999999' ? '888888' : '999999';
        for ($i = 0; $i < 5; $i++) $this->postJson($url.'/confirm', ['code' => $wrong])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->postJson($url.'/code')->assertUnprocessable()->assertJsonPath('status', 'locked');
        $this->postJson($url.'/cancel', ['current_password' => 'CurrentAdmin!234', 'old_email_safe' => false])->assertOk();
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery", ['email' => 'different@gmail.com', 'current_password' => 'CurrentAdmin!234', 'reason' => 'inaccessible', 'identity_verified' => true, 'identity_method' => 'in_person_employment_record'])
            ->assertUnprocessable()->assertJsonPath('status', 'locked');
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
    }

    public function test_recovery_never_sends_a_login_code_to_a_stale_account_email_snapshot(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        $stale = clone $staff;
        [$request, $token] = $this->authorizeRecovery($staff);
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $token, 'current_password' => 'CurrentAdmin!234'])->assertOk();
        app(\App\Services\LoginEmailChallengeService::class)->send($stale, false);
        Notification::assertSentTo($staff, \App\Notifications\ConfirmLoginNotification::class,
            fn ($notice, $channels, $recipient) => $recipient->email === 'new@gmail.com');
    }

    public function test_recovery_admin_expiration_and_snapshot_changes_prevent_authorization(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        $request = $this->startRecovery($staff); $code = $this->latestRecoveryCode();
        $url = "/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}/confirm";
        $this->travel(11)->minutes();
        $this->postJson($url, ['code' => $code])->assertUnprocessable()->assertJsonPath('status', 'expired');
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        $request = $this->startRecovery($staff); $code = $this->latestRecoveryCode();
        $admin->update(['password_hash' => Hash::make('ChangedAdmin!234')]);
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}/confirm", ['code' => $code])->assertUnprocessable();
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
    }

    public function test_recovery_expiration_can_be_renewed_only_by_a_reauthenticated_admin(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        [$request, $token] = $this->authorizeRecovery($staff);
        $this->travel(31)->minutes();
        $this->assertSame(1, app(\App\Services\StaffEmailRecoveryService::class)->recordExpirations());
        $this->assertSame(0, app(\App\Services\StaffEmailRecoveryService::class)->recordExpirations());
        $this->postJson('/api/staff/email-recovery/verify', ['token' => $token])->assertUnprocessable();
        $this->assertTrue($staff->fresh()->hasEmailRecoveryHold());
        $this->useEmailToken($admin->createToken('fresh-admin')->plainTextToken);
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}/resend", ['current_password' => 'CurrentAdmin!234'])->assertOk()->assertJsonPath('recovery.status', 'pending');
        $this->postJson('/api/staff/email-recovery/verify', ['token' => $token])->assertUnprocessable();
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $this->latestRecoveryToken(), 'current_password' => 'CurrentAdmin!234'])->assertOk();
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
    }

    public function test_recovery_five_password_failures_cannot_be_reset_by_resend(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        [$request, $token] = $this->authorizeRecovery($staff);
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/staff/email-recovery/complete', ['token' => $token, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $token, 'current_password' => 'CurrentAdmin!234'])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}/resend", ['current_password' => 'CurrentAdmin!234'])->assertUnprocessable()->assertJsonPath('status', 'locked');
        $this->assertTrue($staff->fresh()->hasEmailRecoveryHold());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recoveryTargetChanges')]
    public function test_recovery_finalization_rechecks_account_and_authorizing_admin(string $change): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        [$request, $token] = $this->authorizeRecovery($staff);
        match ($change) {
            'role' => $staff->update(['staff_subrole' => 'clinic_receptionist']),
            'disabled' => $staff->update(['is_active' => false]),
            'archived' => $staff->update(['is_archived' => true]),
            'password' => $staff->update(['password_hash' => Hash::make('ChangedStaff!234')]),
            'admin' => $admin->update(['is_active' => false]),
            'admin_password' => $admin->update(['password_hash' => Hash::make('ChangedAdmin!234')]),
            'claim' => $this->createUser('customer', 'new@gmail.com', '09172222005'),
        };
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $token, 'current_password' => 'CurrentAdmin!234'])->assertUnprocessable();
        $this->assertSame('current@gmail.com', $staff->fresh()->email);
        $this->assertTrue($staff->fresh()->hasEmailRecoveryHold());
    }

    public static function recoveryTargetChanges(): array
    {
        return array_map(fn ($change) => [$change], ['role', 'disabled', 'archived', 'password', 'admin', 'admin_password', 'claim']);
    }

    public function test_recovery_durable_queue_rolls_back_holds_and_token_rotation_and_suppresses_old_links(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config(['queue.default' => 'database', 'mail.default' => 'array', 'mail.authentication.queue' => 'recovery-test']);
        Notification::swap(new \Illuminate\Notifications\ChannelManager($this->app));
        $trigger = \Illuminate\Support\Facades\DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER reject_recovery_job BEFORE INSERT ON jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'queue unavailable'"
            : "CREATE TRIGGER reject_recovery_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END";
        \Illuminate\Support\Facades\DB::statement($trigger);
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery", [
            'email' => 'new@gmail.com', 'current_password' => 'CurrentAdmin!234', 'reason' => 'inaccessible',
            'identity_verified' => true, 'identity_method' => 'in_person_employment_record',
        ])->assertStatus(503);
        $this->assertDatabaseCount('staff_email_recoveries', 0);
        $this->assertDatabaseCount('staff_email_recovery_events', 0);
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER reject_recovery_job');
        $request = $this->startRecovery($staff);
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--queue' => 'recovery-test'])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertSame('admin@gmail.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
        preg_match('/\b[0-9]{6}\b/', $messages[0]->getOriginalMessage()->getTextBody(), $digits);
        $url = "/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}";
        \Illuminate\Support\Facades\DB::statement($trigger);
        $staff->createToken('staff');
        $this->postJson($url.'/confirm', ['code' => $digits[0]])->assertStatus(503)->assertDontSee('queue unavailable');
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        $this->assertSame(1, $staff->tokens()->count());
        $this->assertDatabaseMissing('staff_email_recovery_events', ['event' => 'authorized']);
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER reject_recovery_job');
        $this->postJson($url.'/confirm', ['code' => $digits[0]])->assertOk();
        $before = \App\Models\StaffEmailRecovery::find($request['id'])->getAttributes();
        $this->travel(61)->seconds();
        \Illuminate\Support\Facades\DB::statement($trigger);
        $this->postJson($url.'/resend', ['current_password' => 'CurrentAdmin!234'])->assertStatus(503);
        $this->assertSame($before, \App\Models\StaffEmailRecovery::find($request['id'])->getAttributes());
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER reject_recovery_job');
        $this->postJson($url.'/resend', ['current_password' => 'CurrentAdmin!234'])->assertOk();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--queue' => 'recovery-test'])->assertExitCode(0);
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $newMailbox = collect($messages)->filter(fn ($mail) => $mail->getOriginalMessage()->getTo()[0]->getAddress() === 'new@gmail.com');
        $this->assertCount(1, $newMailbox, 'Superseded recovery links must not be delivered.');
        $this->assertCount(1, collect($messages)->filter(fn ($mail) => $mail->getOriginalMessage()->getTo()[0]->getAddress() === 'current@gmail.com'), 'Security notices must use the configured authentication queue.');
        foreach ($messages as $mail) {
            if ($mail->getOriginalMessage()->getTo()[0]->getAddress() === 'current@gmail.com') {
                $body = $mail->getOriginalMessage()->getTextBody();
                $this->assertStringNotContainsString('new@gmail.com', $body);
                $this->assertStringNotContainsString('confirm-email-recovery', $body);
            }
        }
    }

    public function test_recovery_migration_preserves_existing_accounts_and_is_reversible(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        $before = $staff->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_25_000003_create_staff_email_recoveries.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('staff_email_recoveries'));
        $migration->up();
        $this->assertSame($before, $staff->fresh()->getAttributes());
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_recovery_completion_survives_an_informational_notice_queue_failure(): void
    {
        [$admin, $staff] = $this->recoveryFixture();
        [$request, $token] = $this->authorizeRecovery($staff);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('private notice failure'));
        $this->postJson('/api/staff/email-recovery/complete', ['token' => $token, 'current_password' => 'CurrentAdmin!234'])->assertOk()->assertDontSee('private notice failure');
        $this->assertSame('new@gmail.com', $staff->fresh()->email);
        $this->assertFalse($staff->fresh()->hasEmailRecoveryHold());
        $this->assertDatabaseHas('staff_email_recoveries', ['id' => $request['id'], 'status' => 'completed', 'token_hash' => null]);
    }

    private function authorizeRecovery(User $staff, array $override = []): array
    {
        $request = $this->startRecovery($staff, $override);
        $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery/{$request['id']}/confirm", ['code' => $this->latestRecoveryCode()])->assertOk();
        return [$request, $this->latestRecoveryToken()];
    }

    private function latestRecoveryToken(): string
    {
        return explode('#', Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable, \App\Notifications\StaffEmailRecoveryLinkNotification::class)->last()->recoveryUrl)[1];
    }

    private function recoveryFixture(string $type = 'clinic', string $subrole = 'veterinarian'): array
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'admin@gmail.com', '09172222001', 'Admin');
        $staff = $this->createUser('staff', 'current@gmail.com', '09172222002', 'Staff');
        $staff->update(['staff_type' => $type, 'staff_subrole' => $subrole]);
        $token = $admin->createToken('admin')->plainTextToken;
        $this->useEmailToken($token);
        return [$admin, $staff, $token];
    }

    private function startRecovery(User $staff, array $override = []): array
    {
        return $this->postJson("/api/admin/security/staff/{$staff->user_id}/email-recovery", array_merge([
            'email' => 'new@gmail.com', 'current_password' => 'CurrentAdmin!234',
            'reason' => 'inaccessible', 'identity_verified' => true, 'identity_method' => 'in_person_employment_record',
        ], $override))->assertAccepted()->json('recovery');
    }

    private function latestRecoveryCode(): string
    {
        return Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable, \App\Notifications\VerifyAdminStaffRecoveryNotification::class)->last()->code;
    }

    private function emailStaffFixture(): array
    {
        Notification::fake();
        $staff = $this->createUser('staff', 'current@gmail.com', '09171111099', 'ExistingStaff');
        $staff->update(['staff_type' => 'clinic', 'staff_subrole' => 'veterinarian']);
        $token = $staff->createToken('staff')->plainTextToken;
        $this->useEmailToken($token);
        $staff->withAccessToken($staff->tokens()->firstOrFail());
        return [$staff, $token];
    }

    private function useEmailToken(string $token): void
    {
        $this->withToken($token);
        $this->app['auth']->forgetGuards();
    }

    private function startEmailChange(string $email = 'new@gmail.com'): array
    {
        return $this->postJson('/api/settings/security/email-change', ['email' => $email, 'current_password' => 'CurrentAdmin!234'])
            ->assertAccepted()->json('pending_email_change');
    }

    private function latestEmailCode(): string
    {
        return Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable, \App\Notifications\VerifyStaffEmailChangeNotification::class)->last()->code;
    }

    private function createUser(
        string $role,
        string $email,
        string $phone,
        ?string $username = null,
    ): User {
        return User::query()->create([
            'first_name' => ucfirst($role),
            'last_name' => 'Bethlehem',
            'username' => $username,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => Hash::make('CurrentAdmin!234'),
            'role' => $role,
            'customer_tier' => 'new',
            'is_active' => true,
            'is_archived' => false,
            'email_verified_at' => now(),
        ]);
    }
}
