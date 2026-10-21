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
            $table->timestamp('expires_at');
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
        (require database_path('migrations/2026_08_28_000003_create_password_reset_requests_table.php'))->up();
    }

    protected function tearDown(): void
    {
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
        $staff = $this->createUser('staff', 'staff-placeholder@example.test', '09170001002');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/security/accounts')
            ->assertOk()
            ->assertJsonPath('admin.username', 'Admin')
            ->assertJsonPath('admin.email', 'bethlehem.admin.test@gmail.com')
            ->assertJsonPath('staff.0.user_id', $staff->user_id)
            ->assertJsonPath('staff.0.username', null)
            ->assertJsonPath('staff.0.email', 'staff-placeholder@example.test');
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
        $staff = $this->createUser('staff', 'staff-placeholder@example.test', '09170001005');
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
        $staff = $this->createUser('staff', 'staff@example.test', '09170001007');
        Sanctum::actingAs($staff);

        $this->getJson('/api/admin/security/accounts')->assertForbidden();
    }

    public function test_staff_can_only_change_their_own_password_after_email_code_verification(): void
    {
        Notification::fake();
        $staff = $this->createUser('staff', 'staff@example.test', '09170001009', 'clinicstaff');
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
        $admin = $this->createUser('admin', 'admin@example.test', '09170001999', 'Admin');
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
        $admin = $this->createUser('admin', 'admin@example.test', '09170001999', 'Admin');
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
        $admin = $this->createUser('admin', 'admin@example.test', '09170001999', 'Admin');
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
        $admin = $this->createUser('admin', 'admin@example.test', '09170001999', 'Admin');
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
