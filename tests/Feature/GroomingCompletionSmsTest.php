<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Jobs\SendGroomingCompletionSms;
use App\Models\Booking;
use App\Services\GroomingCompletionSmsService;
use App\Services\SmsSendBudget;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingCompletionSmsTest extends TestCase
{
    use \Tests\Support\CreatesGroomingVisitFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGroomingVisitFixture();
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('customer');
            $t->string('phone')->nullable();
            $t->dateTime('phone_verified_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_archived')->default(false);
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->unsignedBigInteger('walkin_id')->nullable();
            $t->string('booking_type')->nullable();
        });
        (require database_path('migrations/2026_06_28_200718_create_walkins_table.php'))->up();
        (require database_path('migrations/2026_08_10_223000_create_unregistered_customers_table.php'))->up();
        Schema::table('walkins', fn (Blueprint $t) => $t->foreignId('unregistered_customer_id')->nullable()->constrained());
        (require database_path('migrations/2026_10_25_000004_create_grooming_completion_sms.php'))->up();
        (require database_path('migrations/2026_11_15_000001_add_walkin_recipient_to_grooming_completion_sms.php'))->up();
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        config(['services.grooming_completion_sms.enabled' => true, 'services.iprogsms.api_token' => 'fake-token']);
        DB::table('users')->update(['phone' => '09171234567', 'phone_verified_at' => now()]);
        DB::table('bookings')->update(['status' => 'in_progress', 'grooming_started_at' => now()]);
        DB::table('booking_pets')->update(['grooming_state' => 'in_progress', 'grooming_start_time' => now()]);
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake(['*/sms_messages' => Http::response(['status' => 200, 'message_id' => 'iSms-fixture'])]);
    }

    private function deliver(): void
    {
        (new SendGroomingCompletionSms((int) DB::table('grooming_completion_sms')->value('id')))->handle(app(GroomingCompletionSmsService::class));
    }

    private function attachWalkin(string $phone, ?int $userId = null, ?int $customerId = null): int
    {
        $walkinId = DB::table('walkins')->insertGetId([
            'fname' => 'Walk-in', 'lname' => 'Owner', 'phone' => $phone,
            'user_id' => $userId, 'unregistered_customer_id' => $customerId,
        ]);
        DB::table('bookings')->where('booking_id', 1)->update(['walkin_id' => $walkinId, 'booking_type' => 'walk_in']);

        return $walkinId;
    }

    public function test_individual_pets_create_one_event_only_after_entire_visit_finishes(): void
    {
        $controller = new AdminBookingController;
        $this->assertSame(200, $controller->markPetDone(1, 1)->getStatusCode());
        $this->assertSame(0, DB::table('grooming_completion_sms')->count());
        $this->assertSame('grooming_finished', DB::table('customer_notifications')->value('type'));
        $this->assertSame(200, $controller->markPetDone(1, 2)->getStatusCode());
        $this->assertSame(1, DB::table('grooming_completion_sms')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        Http::assertNothingSent();
        $this->deliver();
        $this->deliver();
        $this->assertSame(422, $controller->markPetDone(1, 2)->getStatusCode());
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['message'] === "Your pets' grooming is complete.\nPlease visit the clinic for pickup.");
        $this->assertSame('accepted', DB::table('grooming_completion_sms')->value('status'));
        $this->assertSame('iSms-fixture', DB::table('grooming_completion_sms')->value('provider_message_id'));
        $this->assertSame(1, DB::table('customer_notifications')->where('type', 'ready_for_pickup')->count());
        Notification::assertNothingSent();
    }

    public function test_single_pet_uses_actual_name_and_bulk_finish_is_deduplicated(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 2)->delete();
        DB::table('pets')->where('pet_id', 1)->update(['pet_name' => 'Joseph']);
        $this->assertSame(200, (new AdminBookingController)->markDone(1)->getStatusCode());
        app(GroomingCompletionSmsService::class)->record(Booking::find(1));
        $this->assertSame(1, DB::table('grooming_completion_sms')->count());
        $this->deliver();
        $this->deliver();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['phone_number'] === '09171234567' && $r['message'] === "Joseph's grooming is complete.\nPlease visit the clinic for pickup.");
        Notification::assertNothingSent();
    }

    public function test_canceling_last_unstarted_pet_completes_finished_remainder(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 2)->update(['grooming_state' => 'not_started', 'grooming_start_time' => null]);
        (new AdminBookingController)->markPetDone(1, 1);
        $this->assertSame(0, DB::table('grooming_completion_sms')->count());
        $request = Request::create('/cancel', 'POST', ['cancellation_reason' => 'Customer canceled.']);
        $request->setUserResolver(fn () => null);
        $this->assertSame(200, (new AdminBookingController)->cancelPet($request, 1, 2)->getStatusCode());
        $this->deliver();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['message'], "Hopper's grooming is complete."));
    }

    public function test_unverified_online_customer_never_gets_an_event(): void
    {
        DB::table('users')->update(['phone_verified_at' => null]);
        (new AdminBookingController)->markDone(1);
        $this->assertDatabaseCount('grooming_completion_sms', 0);
        Http::assertNothingSent();
    }

    public function test_visit_without_a_customer_or_walkin_never_gets_an_event(): void
    {
        DB::table('bookings')->update(['user_id' => null]);
        (new AdminBookingController)->markDone(1);
        $this->assertDatabaseCount('grooming_completion_sms', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('registeredWalkinLinks')]
    public function test_unverified_linked_account_cannot_use_walkin_phone(?int $bookingUserId, ?int $walkinUserId): void
    {
        DB::table('users')->update(['phone_verified_at' => null]);
        DB::table('bookings')->update(['user_id' => $bookingUserId]);
        $this->attachWalkin('09221234567', $walkinUserId);
        (new AdminBookingController)->markDone(1);
        $this->assertDatabaseCount('grooming_completion_sms', 0);
        Http::assertNothingSent();
    }

    public static function registeredWalkinLinks(): array
    {
        return ['booking link' => [1, null], 'walk-in link' => [null, 1], 'both links' => [1, 1]];
    }

    public function test_walkin_linked_only_on_visit_uses_the_verified_account_phone(): void
    {
        DB::table('bookings')->update(['user_id' => null]);
        $this->attachWalkin('09221234567', 1);
        (new AdminBookingController)->markDone(1);
        $this->assertDatabaseHas('grooming_completion_sms', ['booking_id' => 1, 'user_id' => 1]);
        $this->deliver();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['phone_number'] === '09171234567');
    }

    public function test_walkin_with_conflicting_registered_owners_never_gets_an_event(): void
    {
        DB::table('users')->insert([
            'user_id' => 2, 'first_name' => 'Another', 'last_name' => 'Owner',
            'phone' => '09221234567', 'phone_verified_at' => now(),
        ]);
        $this->attachWalkin('09761234567', 2);
        (new AdminBookingController)->markDone(1);
        $this->assertDatabaseCount('grooming_completion_sms', 0);
        Http::assertNothingSent();
    }

    public function test_new_walkin_receives_one_completion_sms_before_owner_registration(): void
    {
        config(['services.sms_verification.user_hourly_limit' => 0, 'services.sms_verification.user_daily_limit' => 0]);
        DB::table('bookings')->update(['user_id' => null]);
        $walkinId = $this->attachWalkin('+63 (922) 123-4567');
        DB::table('booking_pets')->where('booking_pet_id', 2)->delete();
        DB::table('pets')->where('pet_id', 1)->update(['pet_name' => 'Joseph']);

        $controller = new AdminBookingController;
        $this->assertSame(200, $controller->markDone(1)->getStatusCode());
        $this->assertSame(422, $controller->markDone(1)->getStatusCode());
        app(GroomingCompletionSmsService::class)->record(Booking::find(1));
        $this->assertDatabaseCount('grooming_completion_sms', 1);
        $this->assertDatabaseHas('grooming_completion_sms', ['booking_id' => 1, 'user_id' => null, 'walkin_id' => $walkinId]);
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
        $this->deliver();
        $this->deliver();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['phone_number'] === '09221234567' && $r['message'] === "Joseph's grooming is complete.\nPlease visit the clinic for pickup.");
        $this->assertDatabaseHas('grooming_completion_sms', ['status' => 'accepted']);
        $this->assertDatabaseCount('unregistered_customers', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_unregistered_owner_receives_multi_pet_sms_at_the_visit_phone(): void
    {
        $customerId = DB::table('unregistered_customers')->insertGetId([
            'first_name' => 'Existing', 'last_name' => 'Owner', 'phone' => '09761234567',
        ]);
        DB::table('bookings')->update(['user_id' => null]);
        $walkinId = $this->attachWalkin('09221234567', customerId: $customerId);

        $controller = new AdminBookingController;
        $this->assertSame(200, $controller->markPetDone(1, 1)->getStatusCode());
        $this->assertDatabaseCount('grooming_completion_sms', 0);
        $this->assertSame(200, $controller->markPetDone(1, 2)->getStatusCode());
        DB::table('walkins')->where('id', $walkinId)->update(['phone' => '09181234567']);
        $this->deliver();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['phone_number'] === '09181234567' && $r['message'] === "Your pets' grooming is complete.\nPlease visit the clinic for pickup.");
    }

    #[DataProvider('ineligibleWalkinPhones')]
    public function test_ineligible_walkin_phone_never_gets_an_event(string $phone): void
    {
        DB::table('bookings')->update(['user_id' => null]);
        $this->attachWalkin($phone);
        $this->assertSame(200, (new AdminBookingController)->markDone(1)->getStatusCode());
        $this->assertDatabaseCount('grooming_completion_sms', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public static function ineligibleWalkinPhones(): array
    {
        return [
            'unsupported 0901 prefix' => ['09011234567'],
            'non-09 phone' => ['08131234567'],
            'malformed phone' => ['0917ABC4567'],
        ];
    }

    #[DataProvider('ineligibleWalkinPhones')]
    public function test_worker_skips_walkin_phone_that_becomes_ineligible(string $phone): void
    {
        DB::table('bookings')->update(['user_id' => null]);
        $walkinId = $this->attachWalkin('09221234567');
        (new AdminBookingController)->markDone(1);
        DB::table('walkins')->where('id', $walkinId)->update(['phone' => $phone]);
        $this->deliver();
        $this->assertDatabaseHas('grooming_completion_sms', ['booking_id' => 1, 'status' => 'skipped']);
        Http::assertNothingSent();
    }

    public function test_worker_skips_when_the_booking_points_to_another_walkin(): void
    {
        DB::table('bookings')->update(['user_id' => null]);
        $this->attachWalkin('09221234567');
        (new AdminBookingController)->markDone(1);
        $this->attachWalkin('09761234567');
        $this->deliver();
        $this->assertDatabaseHas('grooming_completion_sms', ['booking_id' => 1, 'status' => 'skipped']);
        Http::assertNothingSent();
    }

    public function test_worker_skips_walkin_event_after_the_visit_links_to_a_verified_account(): void
    {
        DB::table('bookings')->update(['user_id' => null]);
        $walkinId = $this->attachWalkin('09221234567');
        (new AdminBookingController)->markDone(1);
        DB::table('walkins')->where('id', $walkinId)->update(['user_id' => 1]);
        $this->deliver();
        $this->assertDatabaseHas('grooming_completion_sms', ['booking_id' => 1, 'status' => 'skipped']);
        Http::assertNothingSent();
    }

    #[DataProvider('walkinBudgetLimits')]
    public function test_walkin_delivery_obeys_emergency_budgets_without_removed_phone_quotas(string $setting, string $priorPhone): void
    {
        config(['services.sms_verification.'.$setting => 1]);
        DB::table('bookings')->update(['user_id' => null]);
        $this->attachWalkin('+63 922 123 4567');
        (new AdminBookingController)->markDone(1);
        app(SmsSendBudget::class)->reserve(1, $priorPhone);

        $this->deliver();

        $removed = in_array($setting, ['phone_hourly_limit', 'phone_daily_limit'], true);
        $this->assertDatabaseHas('grooming_completion_sms', ['booking_id' => 1, 'status' => $removed ? 'accepted' : 'failed']);
        if ($removed) Http::assertSentCount(1);
        else Http::assertNothingSent();
    }

    public static function walkinBudgetLimits(): array
    {
        return [
            'phone hourly' => ['phone_hourly_limit', '09221234567'],
            'phone daily' => ['phone_daily_limit', '09221234567'],
            'global hourly' => ['global_hourly_limit', '09171234567'],
            'global daily' => ['global_daily_limit', '09171234567'],
        ];
    }

    public function test_worker_rechecks_verified_recipient(): void
    {
        (new AdminBookingController)->markDone(1);
        DB::table('users')->update(['phone_verified_at' => null]);
        $this->deliver();
        Http::assertNothingSent();
        $this->assertSame('skipped', DB::table('grooming_completion_sms')->value('status'));
    }

    public function test_linked_walkin_uses_existing_verified_phone_while_replacement_is_pending(): void
    {
        $this->attachWalkin('09221234567', 1);
        (require database_path('migrations/2026_10_22_000002_create_phone_verification_attempts.php'))->up();
        DB::table('phone_verification_attempts')->insert(['user_id' => 1, 'phone' => '09991234567',
            'generation' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'active',
            'expires_at' => now()->addMinutes(5), 'resend_at' => now()->addMinute(), 'attempts_reset_at' => now()->addHour()]);
        (new AdminBookingController)->markDone(1);
        $this->deliver();
        Http::assertSent(fn ($r) => $r['phone_number'] === '09171234567');
        Http::assertSentCount(1);
    }

    public function test_overlapping_job_claim_cannot_submit_again_and_provider_is_outside_transaction(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            $this->assertSame(0, DB::transactionLevel());
            $this->deliver();
            return Http::response(['status' => 200, 'message_id' => 'iSms-overlap']);
        });
        (new AdminBookingController)->markDone(1);
        $this->deliver();
        $this->assertSame(1, $calls);
        $this->assertSame('accepted', DB::table('grooming_completion_sms')->value('status'));
        try {
            DB::table('grooming_completion_sms')->insert(['booking_id' => 1, 'user_id' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('Duplicate booking events must be rejected by the database.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, DB::table('grooming_completion_sms')->count());
        }
    }

    public function test_changed_verified_number_is_reloaded_before_sending(): void
    {
        (new AdminBookingController)->markDone(1);
        DB::table('users')->update(['phone' => '09991234567', 'phone_verified_at' => now()]);
        $this->deliver();
        Http::assertSent(fn ($r) => $r['phone_number'] === '09991234567');
        Http::assertSentCount(1);
    }

    public function test_timeout_is_uncertain_and_never_automatically_resubmitted(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('fake-token sensitive response'));
        $this->assertSame(200, (new AdminBookingController)->markDone(1)->getStatusCode());
        $this->deliver();
        $this->deliver();
        $this->assertSame('uncertain', DB::table('grooming_completion_sms')->value('status'));
        $this->assertSame('for_payment', Booking::find(1)->status);
    }

    public function test_rejected_provider_response_is_not_delivery_confirmation(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*/sms_messages' => Http::response(['status' => 500, 'message' => 'private provider response'], 200)]);
        (new AdminBookingController)->markDone(1);
        $this->deliver();
        $this->assertSame('failed', DB::table('grooming_completion_sms')->value('status'));
        $this->deliver();
        Http::assertSentCount(1);
    }

    public function test_event_and_post_commit_job_disappear_on_transaction_rollback(): void
    {
        DB::beginTransaction();
        (new AdminBookingController)->markDone(1);
        $this->assertSame(1, DB::table('grooming_completion_sms')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('grooming_completion_sms')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    public function test_queue_failure_leaves_recoverable_pending_event(): void
    {
        Schema::drop('jobs');
        $this->assertSame(200, (new AdminBookingController)->markDone(1)->getStatusCode());
        $this->assertSame('pending', DB::table('grooming_completion_sms')->value('status'));
        Schema::drop('job_batches');
        Schema::drop('failed_jobs');
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        app(GroomingCompletionSmsService::class)->dispatchPending();
        $this->assertSame(1, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    public function test_disabled_flag_creates_no_paid_work(): void
    {
        config(['services.grooming_completion_sms.enabled' => false]);
        (new AdminBookingController)->markDone(1);
        $this->assertSame(0, DB::table('grooming_completion_sms')->count());
        Http::assertNothingSent();
    }
}
