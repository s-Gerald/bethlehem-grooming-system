<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Jobs\SendGroomingCompletionSms;
use App\Models\Booking;
use App\Services\GroomingCompletionSmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
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
        (require database_path('migrations/2026_10_25_000004_create_grooming_completion_sms.php'))->up();
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
        Http::assertSent(fn ($r) => $r['message'] === "Bethlehem Animal Clinic\nYour pets' grooming is complete.\nPlease visit the clinic for pickup.");
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
        Http::assertSent(fn ($r) => $r['phone_number'] === '09171234567' && $r['message'] === "Bethlehem Animal Clinic\nJoseph's grooming is complete.\nPlease visit the clinic for pickup.");
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

    public function test_unverified_or_unlinked_walkin_never_gets_an_event(): void
    {
        DB::table('users')->update(['phone_verified_at' => null]);
        (new AdminBookingController)->markDone(1);
        $this->assertSame(0, DB::table('grooming_completion_sms')->count());
        DB::table('bookings')->update(['user_id' => null]);
        app(GroomingCompletionSmsService::class)->record(Booking::find(1));
        $this->assertSame(0, DB::table('grooming_completion_sms')->count());
        Http::assertNothingSent();
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
        Schema::table('bookings', function (Blueprint $t) {
            $t->unsignedInteger('walkin_id')->nullable();
            $t->string('booking_type')->nullable();
        });
        DB::table('bookings')->update(['walkin_id' => 99, 'booking_type' => 'walk_in']);
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
