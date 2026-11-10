<?php

namespace Tests\Feature;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\CustomerRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

// Share the existing authentication schema without inheriting unrelated tests.
class VerifyFirstRegistrationTest extends \Tests\TestCase
{
    use \Tests\Support\CreatesAuthenticationSchema;
    use \Tests\Support\ConcurrentAuthenticationRequests;

    private string $credential;
    private string $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticationSchema();
        Notification::fake();
        $this->credential = str_repeat('a', 64);
        $this->browser = str_repeat('b', 64);
        $this->originalBrowser();
    }

    protected function tearDown(): void
    {
        $this->dropAuthenticationSchema();
        parent::tearDown();
    }

    private function originalBrowser(): void
    {
        $this->withUnencryptedCookie(CustomerRegistrationService::BROWSER_COOKIE, $this->browser)->withCredentials();
    }

    private function details(array $changes = []): array
    {
        return [...[
            'first_name' => 'MARIA CLARA', 'last_name' => 'PEÑA', 'username' => 'newcustomer',
            'email' => 'new.customer@gmail.com', 'phone' => '+639171234567',
            'password' => 'strong-password', 'password_confirmation' => 'strong-password', 'terms_accepted' => true,
            'registration_token' => $this->credential,
        ], ...$changes];
    }

    private function start(array $changes = [])
    {
        return $this->postJson('/api/register', $this->details($changes));
    }

    private function link(?PendingCustomerRegistration $pending = null): string
    {
        return Notification::sent($pending ?? PendingCustomerRegistration::sole(), VerifyEmailNotification::class)->last()->token;
    }

    private function approve(): void
    {
        $this->postJson('/api/email/verify', ['token' => $this->link()])->assertOk()
            ->assertJsonPath('registration_approved', true)->assertJsonMissingPath('token')->assertJsonMissingPath('details');
    }

    public static function invalidSignupDetails(): array
    {
        return [
            'missing name' => ['first_name', ['first_name' => null]],
            'first name' => ['first_name', ['first_name' => 'Maria123']],
            'last name' => ['last_name', ['last_name' => '123']],
            'email format' => ['email', ['email' => 'invalid-email']],
            'email policy' => ['email', ['email' => 'customer@example.com']],
            'email type' => ['email', ['email' => ['customer@gmail.com']]],
            'username' => ['username', ['username' => '12']],
            'phone' => ['phone', ['phone' => '09123']],
            'password length' => ['password', ['password' => 'short', 'password_confirmation' => 'short']],
            'password characters' => ['password', ['password' => 'strong-passwörd', 'password_confirmation' => 'strong-passwörd']],
            'confirmation' => ['password_confirmation', ['password_confirmation' => 'different']],
            'predictable password' => ['password', ['password' => 'newcustomer123', 'password_confirmation' => 'newcustomer123']],
            'terms declined' => ['terms_accepted', ['terms_accepted' => false]],
            'terms missing' => ['terms_accepted', ['terms_accepted' => null]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignupDetails')]
    public function test_start_rejects_invalid_details_before_email_delivery(string $field, array $changes): void
    {
        $this->start($changes)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNoVerificationAttempt();
    }

    public static function signupConflicts(): array
    {
        return [
            'email' => ['email', ['email' => 'NEW.Customer@GMAIL.COM']],
            'same names and phone' => ['phone', ['email' => 'other@gmail.com', 'username' => null]],
            'different names and phone' => ['phone', ['email' => 'other@gmail.com', 'username' => null, 'first_name' => 'Jane', 'last_name' => 'Doe']],
            'username' => ['username', ['email' => 'other@gmail.com', 'phone' => null, 'username' => 'NEWCUSTOMER']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signupConflicts')]
    public function test_start_rejects_existing_account_identifiers_before_email_delivery(string $field, array $changes): void
    {
        User::factory()->create(['first_name' => 'Maria Clara', 'last_name' => 'Peña',
            'email' => 'new.customer@gmail.com', 'phone' => '09171234567', 'username' => 'newcustomer']);
        $this->start($changes)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNoVerificationAttempt();
    }

    public function test_start_rejects_a_breached_password_before_email_delivery(): void
    {
        $digest = strtoupper(sha1('breached-example'));
        Http::swap((new \Illuminate\Http\Client\Factory)->preventStrayRequests());
        Http::fake(['api.pwnedpasswords.com/range/'.substr($digest, 0, 5) => Http::response(substr($digest, 5).':1')]);
        $this->start(['password' => 'breached-example', 'password_confirmation' => 'breached-example'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'This password is not safe. Choose another.');
        $this->assertNoVerificationAttempt();
    }

    public function test_start_fails_closed_when_password_screening_is_unavailable(): void
    {
        Http::swap((new \Illuminate\Http\Client\Factory)->preventStrayRequests());
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);
        $this->start()->assertServiceUnavailable()->assertJsonPath('code', 'password_screening_unavailable')
            ->assertHeader('Retry-After', '60');
        $this->assertNoVerificationAttempt();
    }

    private function assertNoVerificationAttempt(): void
    {
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        Notification::assertNothingSent();
    }

    public function test_start_stores_only_email_and_verification_metadata(): void
    {
        $this->start()->assertAccepted()->assertJsonMissingPath('token');
        $row = DB::table('pending_customer_registrations')->sole();
        foreach (['first_name', 'last_name', 'username', 'phone', 'password', 'password_hash', 'password_confirmation', 'terms_accepted_at'] as $field) {
            $this->assertFalse(property_exists($row, $field), $field.' must not be persisted before approval');
        }
        $this->assertNotSame($this->credential, $row->registration_edit_token_hash);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/register/finalize', $this->details())->assertUnprocessable();
    }

    public function test_cross_device_approval_only_allows_original_browser_to_create_account_and_first_session(): void
    {
        $this->start()->assertAccepted();
        $link = $this->link();
        $this->travel(20)->seconds();
        // A new device sends no original cookie, over a different network and browser.
        $approval = $this->call('POST', '/api/email/verify', ['token' => $link], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'REMOTE_ADDR' => '198.51.100.20', 'HTTP_USER_AGENT' => 'Mobile Safari',
        ])->assertOk()->assertJsonPath('registration_approved', true)->assertJsonMissingPath('token');
        $this->assertCount(0, $approval->headers->getCookies());
        $this->withUnencryptedCookie(CustomerRegistrationService::BROWSER_COOKIE, str_repeat('c', 64))->withCredentials();
        $this->postJson('/api/email/verify', ['token' => $link])->assertUnprocessable()->assertJsonPath('code', 'registration_link_consumed');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/email/registration/status', ['registration_token' => $this->credential])->assertUnprocessable();
        $this->postJson('/api/register/finalize', $this->details())->assertUnprocessable();
        $this->originalBrowser();
        $this->postJson('/api/email/registration/status', ['registration_token' => $this->credential])->assertOk()
            ->assertJsonPath('state', 'approved')->assertJsonPath('server_now', now()->toIso8601String());
        Http::assertSentCount(1);
        $completed = $this->postJson('/api/register/finalize', $this->details())->assertOk()->assertJsonPath('user.role', 'customer');
        Http::assertSentCount(2);
        $this->assertNotNull($this->accountSessionCookie($completed));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $user = User::sole();
        $this->assertTrue(Hash::check('strong-password', $user->password_hash));
        $this->assertSame('Maria Clara', $user->first_name);
        $this->assertSame('09171234567', $user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertSame('2026-11-01', $user->terms_accepted_version);
        $this->withAccountSession($completed)->getJson('/api/me')->assertOk();
    }

    public function test_lost_finalize_response_replays_the_same_first_session_without_storing_session_secrets(): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        $first = $this->postJson('/api/register/finalize', $this->details())->assertOk();
        $retry = $this->postJson('/api/register/finalize', ['registration_token' => $this->credential])->assertOk();
        $this->assertSame($first->json('token'), $retry->json('token'));
        $secret = $this->accountSessionCookie($first)->getValue();
        $this->assertSame($secret, $this->accountSessionCookie($retry)->getValue());
        $this->assertStringNotContainsString(explode('|', $secret)[1], json_encode(DB::table('pending_customer_registrations')->sole()));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        DB::table('personal_access_tokens')->delete();
        $this->postJson('/api/register/finalize', $this->details())->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_consumed_link_cannot_issue_an_account_or_session_and_approval_survives_link_expiry(): void
    {
        $this->start()->assertAccepted();
        $link = $this->link();
        $this->get('/verify-email?token='.$link)->assertOk();
        $this->assertNull(PendingCustomerRegistration::sole()->registration_approved_at);
        $this->approve();
        $this->postJson('/api/email/verify', ['token' => $link])->assertUnprocessable()->assertJsonPath('code', 'registration_link_consumed');
        $this->travel(31)->minutes();
        $this->postJson('/api/email/registration/status', ['registration_token' => $this->credential])->assertOk()->assertJsonPath('state', 'approved');
        $this->postJson('/api/register/finalize', $this->details())->assertOk();
    }

    public function test_lost_memory_can_reenter_details_without_reverification_and_must_revalidate_all_fields(): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        $this->postJson('/api/register/finalize', ['registration_token' => $this->credential])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'password', 'terms_accepted']);
        foreach ([['password_confirmation' => 'different'], ['password' => 'short', 'password_confirmation' => 'short'],
            ['first_name' => '123'], ['phone' => '123'], ['username' => '12'], ['terms_accepted' => false],
            ['email' => 'another@gmail.com']] as $changes) {
            $this->postJson('/api/register/finalize', $this->details($changes))->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/register/finalize', $this->details(['username' => 'changedcustomer', 'phone' => null]))->assertOk();
    }

    public function test_same_attempt_retries_resume_without_superseding_links_or_resending_mail(): void
    {
        $this->start()->assertAccepted();
        $link = $this->link();
        $this->start()->assertAccepted()->assertJsonPath('registration_state', 'resumed');
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertCount(1, Notification::sent(PendingCustomerRegistration::sole(), VerifyEmailNotification::class));
        $this->assertSame(hash('sha256', $link), PendingCustomerRegistration::sole()->email_verification_token);
        $this->approve();
        $this->start()->assertAccepted()->assertJsonPath('state', 'approved');
        $this->postJson('/api/email/resend', ['registration_token' => $this->credential])->assertOk()->assertJsonPath('state', 'approved');
        $this->assertNotNull(PendingCustomerRegistration::sole()->registration_approved_at);
    }

    public function test_resubmitting_edited_details_revalidates_without_discarding_approval(): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        User::factory()->create(['phone' => '09181111222', 'username' => 'takenusername']);
        $this->start(['phone' => '09181111222', 'username' => 'takenusername'])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'username']);
        $this->start(['password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->start(['phone' => null, 'username' => 'changedcustomer'])->assertAccepted()
            ->assertJsonPath('state', 'approved')->assertJsonMissingPath('email_delivery_token');
        $this->assertCount(1, Notification::sent(PendingCustomerRegistration::sole(), VerifyEmailNotification::class));
    }

    public function test_same_email_attempts_are_independent_and_neither_reserves_profile_details(): void
    {
        $this->start()->assertAccepted();
        $first = PendingCustomerRegistration::sole();
        $firstLink = $this->link($first);
        $this->withUnencryptedCookie(CustomerRegistrationService::BROWSER_COOKIE, str_repeat('c', 64))->withCredentials();
        $otherToken = str_repeat('d', 64);
        $this->start(['registration_token' => $otherToken])->assertTooManyRequests();
        $this->assertSame(hash('sha256', $firstLink), $first->fresh()->email_verification_token);
        $this->travel(61)->seconds();
        $this->start(['registration_token' => $otherToken])->assertAccepted()->assertJsonPath('registration_token', $otherToken);
        $this->assertDatabaseCount('pending_customer_registrations', 2);
        $this->postJson('/api/email/registration/status', ['registration_token' => $this->credential])->assertUnprocessable()->assertJsonMissingPath('email');
        $this->postJson('/api/email/verify', ['token' => $firstLink])->assertOk();
        $this->postJson('/api/email/registration/status', ['registration_token' => $otherToken])->assertOk()->assertJsonPath('state', 'pending');
        $this->originalBrowser();
        $this->postJson('/api/register/finalize', $this->details())->assertOk();
        $second = PendingCustomerRegistration::whereKeyNot($first->getKey())->sole();
        $this->postJson('/api/email/verify', ['token' => $this->link($second)])->assertOk();
        $this->withUnencryptedCookie(CustomerRegistrationService::BROWSER_COOKIE, str_repeat('c', 64))->withCredentials();
        $this->postJson('/api/register/finalize', $this->details(['registration_token' => $otherToken]))->assertUnprocessable()->assertJsonPath('conflicts.email', 'account_exists');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_distinct_emails_can_use_same_username_and_phone_until_one_finalizes(): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        $first = $this->credential;
        $this->credential = str_repeat('e', 64);
        $this->start(['email' => 'second@gmail.com'])->assertAccepted();
        $this->postJson('/api/email/verify', ['token' => $this->link(PendingCustomerRegistration::where('email', 'second@gmail.com')->sole())])->assertOk();
        $this->postJson('/api/register/finalize', $this->details(['registration_token' => $first]))->assertOk();
        $this->postJson('/api/register/finalize', $this->details(['email' => 'second@gmail.com', 'username' => 'NEWCUSTOMER']))->assertUnprocessable()
            ->assertJsonPath('conflicts.username', 'username_taken')->assertJsonPath('conflicts.phone', 'account_exists');
        $this->postJson('/api/register/finalize', $this->details(['email' => 'second@gmail.com', 'username' => 'anothercustomer', 'phone' => null]))->assertOk();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_rejected_resend_preserves_current_link_authorized_resend_replaces_only_its_attempt(): void
    {
        $this->start()->assertAccepted();
        $old = $this->link();
        $this->postJson('/api/email/resend', ['registration_token' => $this->credential])->assertTooManyRequests();
        $this->assertSame(hash('sha256', $old), PendingCustomerRegistration::sole()->email_verification_token);
        $this->travel(61)->seconds();
        $this->postJson('/api/email/resend', ['registration_token' => $this->credential])->assertOk();
        $this->postJson('/api/email/verify', ['token' => $old])->assertUnprocessable()->assertJsonPath('code', 'registration_link_invalid');
        $this->approve();
    }

    public function test_link_expiry_retention_cleanup_and_cancellation_do_not_delete_completed_accounts(): void
    {
        $this->start()->assertAccepted();
        $link = $this->link();
        $this->travel(30)->minutes();
        $this->postJson('/api/email/verify', ['token' => $link])->assertUnprocessable()->assertJsonPath('code', 'registration_link_expired');
        $this->postJson('/api/email/resend', ['registration_token' => $this->credential])->assertOk();
        $this->approve();
        $this->postJson('/api/register/finalize', $this->details())->assertOk();
        $this->travel(30)->minutes();
        $this->postJson('/api/register/finalize', $this->details())->assertUnprocessable()->assertJsonPath('code', 'registration_expired');
        $this->assertSame(1, PendingCustomerRegistration::pruneExpired());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->start()->assertUnprocessable()->assertJsonPath('conflicts.email', 'account_exists');
    }

    public function test_session_creation_failure_rolls_back_account_and_preserves_approval_for_retry(): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        DB::statement("CREATE TRIGGER reject_registration_session BEFORE INSERT ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'session unavailable'); END");
        $this->postJson('/api/register/finalize', $this->details())->assertServerError();
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(PendingCustomerRegistration::sole()->registration_completed_at);
        $this->assertNotNull(PendingCustomerRegistration::sole()->registration_approved_at);
        DB::statement('DROP TRIGGER reject_registration_session');
        $this->postJson('/api/register/finalize', $this->details())->assertOk();
    }

    public function test_attempt_controls_require_both_credentials_and_trusted_origin(): void
    {
        $this->start()->assertAccepted();
        foreach (['/api/email/registration/status', '/api/register/finalize', '/api/email/resend'] as $route) {
            $this->postJson($route, [])->assertUnprocessable();
            $this->postJson($route, ['registration_token' => str_repeat('z', 64)])->assertUnprocessable();
            $this->withHeader('Origin', 'https://attacker.example')->postJson($route, $this->details())->assertStatus(419);
            $this->withHeader('Origin', config('app.url'));
        }
        $this->postJson('/api/register/cancel', ['email' => 'new.customer@gmail.com'])->assertUnprocessable();
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->postJson('/api/register/cancel', ['registration_token' => $this->credential])->assertOk();
        $this->assertDatabaseCount('pending_customer_registrations', 0);
    }

    public function test_schema_transition_invalidates_only_old_attempts_and_is_repeatable_and_reversible(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_07_000001_make_customer_registration_verify_first.php');
        $migration->down();
        DB::table('pending_customer_registrations')->insert([
            'first_name' => 'Old', 'last_name' => 'Signup', 'email' => 'old@gmail.com',
            'password_hash' => Hash::make('old-password'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        $this->assertDatabaseCount('pending_customer_registrations', 0);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->start()->assertAccepted();
        $migration->up();
        $this->assertDatabaseCount('pending_customer_registrations', 1);
        $this->assertFalse(Schema::hasColumn('pending_customer_registrations', 'password_hash'));
        $this->assertFalse(Schema::hasIndex('pending_customer_registrations', ['email'], 'unique'));
        $migration->down();
        $this->assertTrue(Schema::hasColumn('pending_customer_registrations', 'password_hash'));
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_finalization_preserves_staff_reservations_and_ignores_spoofed_terms_evidence(): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        Schema::create('pending_staff_accounts', function ($table) {
            $table->id(); $table->string('email'); $table->string('username'); $table->timestamp('expires_at');
        });
        try {
            DB::table('pending_staff_accounts')->insert(['email' => 'staff@gmail.com', 'username' => 'NewCustomer', 'expires_at' => now()->addHour()]);
            $this->start(['email' => 'staff@gmail.com', 'registration_token' => str_repeat('d', 64)])
                ->assertUnprocessable()->assertJsonValidationErrors(['email', 'username']);
            $this->assertDatabaseCount('pending_customer_registrations', 1);
            $this->postJson('/api/register/finalize', $this->details())->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->postJson('/api/register/finalize', $this->details(['username' => null,
                'terms_accepted_version' => 'spoofed', 'terms_accepted_at' => '2000-01-01']))->assertOk();
            $this->assertSame('2026-11-01', User::sole()->terms_accepted_version);
            $this->assertTrue(User::sole()->terms_accepted_at->isToday());
        } finally { Schema::dropIfExists('pending_staff_accounts'); }
    }
    public static function finalizationCollisions(): array
    {
        return [['same_attempt'], ['email'], ['username'], ['phone']];
    }

    public static function resendRaces(): array
    {
        return [['resend', 'resend', 429], ['resend', 'verify', 422], ['verify', 'resend', 200]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('resendRaces')]
    public function test_overlapping_resend_and_verification_preserve_the_winning_link(string $first, string $second, int $status): void
    {
        (require database_path('migrations/0001_01_01_000001_create_cache_table.php'))->up();
        config(['cache.default' => 'database']);
        $this->start()->assertAccepted();
        $link = $this->link();
        $this->travel(61)->seconds();
        $requests = [
            'resend' => ['_verification_endpoint' => '/api/email/resend', 'registration_token' => $this->credential,
                'registration_browser' => $this->browser],
            'verify' => ['_verification_endpoint' => '/api/email/verify', 'token' => $link],
        ];
        $result = $this->assertConcurrentAuthenticationRequests($requests[$first], $requests[$second], $status, 1, 0);
        $this->assertSame($result['first']['link_hash'], $result['pending']['email_verification_token']);
        if ($first === 'resend') {
            $this->assertNotSame(hash('sha256', $link), $result['pending']['email_verification_token']);
            $this->assertNull($result['pending']['registration_approved_at']);
        } else {
            $this->assertSame(hash('sha256', $link), $result['pending']['email_verification_token']);
            $this->assertNotNull($result['pending']['registration_approved_at']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('finalizationCollisions')]
    public function test_overlapping_finalizations_create_one_account_and_one_session(string $collision): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        $firstDetails=$this->details();
        $secondDetails=$firstDetails;
        $secondBrowser=$this->browser;
        if($collision!=='same_attempt') {
            $secondDetails=[...$firstDetails, 'registration_token'=>str_repeat('d',64),
                'email'=>$collision==='email' ? $firstDetails['email'] : 'second@gmail.com',
                'username'=>$collision==='username' ? 'NEWCUSTOMER' : 'differentname',
                'phone'=>$collision==='phone' ? $firstDetails['phone'] : '09181111222'];
            $secondBrowser=str_repeat('f',64);
            PendingCustomerRegistration::create([
                'email'=>$secondDetails['email'], 'registration_edit_token_hash'=>hash('sha256',$secondDetails['registration_token']),
                'registration_browser_token_hash'=>hash('sha256',$secondBrowser), 'registration_approved_at'=>now(),
            ]);
        }
        $this->assertConcurrentAuthenticationRequests(
            [...$firstDetails, '_verification_endpoint'=>'/api/register/finalize', 'registration_browser'=>$this->browser],
            [...$secondDetails, '_verification_endpoint'=>'/api/register/finalize', 'registration_browser'=>$secondBrowser],
            $collision==='same_attempt' ? 200 : 422, $collision==='same_attempt' ? 1 : 2,
        );
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

    public static function invalidSignupPhones(): array
    {
        return [['09123'], ['56775675767'], ['12345678901'], ['08123456789'], ['08131234567'], ['08171234567'], ['08951234567'], ['08961234567'], ['08971234567'], ['08981234567'], ['+63 895-123-4567'], ['8951234567'], ['09031234567'], ['07123456789'], ['091234567890'], ['not-a-phone'], [9123456789], [[]], [['09123456789']]];
    }

    public static function validSignupPhones(): array
    {
        return array_map(fn ($prefix) => [$prefix.'1234567'], ['0917', '0976', '0991', '0999', '0907', '0922', '0927', '0956', '0966', '0977']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('optionalPhones')]
    public function test_matching_names_and_optional_identifiers_are_accepted_at_start_and_finalize(array $phoneInput, ?string $expectedPhone): void
    {
        User::factory()->create(['first_name' => 'Maria Clara', 'last_name' => 'Maria Clara']);
        $details = $this->details(['last_name' => 'MARIA CLARA', 'username' => ' ']);
        unset($details['phone']);
        $details = [...$details, ...$phoneInput];
        $this->postJson('/api/register', $details)->assertAccepted();
        $this->approve();
        $completed=$this->postJson('/api/register/finalize', $details)->assertOk();
        $user = User::where('email', $details['email'])->sole();
        $this->assertSame($expectedPhone, $user->phone);
        $this->assertNull($user->username);
        $this->assertSame('Maria Clara', $user->first_name);
        $this->assertSame('Maria Clara', $user->last_name);
        $this->withAccountSession($completed)->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.phone_verification_status',$expectedPhone ? 'unverified':'not_added');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignupPhones')]
    public function test_finalization_rejects_invalid_mobile_without_consuming_approval(mixed $phone): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        $this->postJson('/api/register/finalize',$this->details(['phone'=>$phone]))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('users',0);
        $this->assertNotNull(PendingCustomerRegistration::sole()->registration_approved_at);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validSignupPhones')]
    public function test_finalization_accepts_all_supported_mobile_networks_without_sms(string $phone): void
    {
        $this->start()->assertAccepted();
        $this->approve();
        $this->postJson('/api/register/finalize',$this->details(['phone'=>$phone]))->assertOk();
        $this->assertSame($phone,User::sole()->phone);
        $this->assertNull(User::sole()->phone_verified_at);
        \Illuminate\Support\Facades\Http::assertNotSent(fn($request)=>!str_starts_with($request->url(),'https://api.pwnedpasswords.com/range/'));
    }

    public function test_email_alias_and_local_part_case_survive_finalization_and_existing_sign_in(): void
    {
        $this->start(['email'=>'  Gerald+Clinic@GMAIL.COM '])->assertAccepted()->assertJsonPath('email','Gerald+Clinic@gmail.com');
        $this->approve();
        $this->postJson('/api/register/finalize',$this->details(['email'=>'Gerald+Clinic@GMAIL.COM']))->assertOk()
            ->assertJsonPath('user.email','Gerald+Clinic@gmail.com');
        $this->postJson('/api/sign-in',['identifier'=>'GERALD+CLINIC@gmail.com','password'=>'strong-password'])->assertAccepted()
            ->assertJsonPath('requires_login_confirmation',true);
    }

}
