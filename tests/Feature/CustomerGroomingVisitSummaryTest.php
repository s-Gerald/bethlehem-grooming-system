<?php

namespace Tests\Feature;

use App\Http\Controllers\BookingController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerGroomingVisitSummaryTest extends TestCase
{
    use \Tests\Support\CreatesPetGroomers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPetGroomers();
        Schema::create('clinic_settings', function (Blueprint $t) { $t->id(); });
        Schema::create('bookings', function (Blueprint $t) {
            $t->increments('booking_id');
            $t->integer('user_id');
            $t->date('booking_date')->nullable();
            $t->string('booking_reference');
            $t->string('status')->default('waiting_to_arrive');
            $t->integer('window_id')->nullable();
            $t->text('internal_staff_note')->nullable();
            $t->boolean('paid')->default(false);
            $t->integer('cancel_count')->default(0);
            $t->dateTime('grooming_started_at')->nullable();
            $t->dateTime('grooming_finished_at')->nullable();
        });
        Schema::create('time_windows', function (Blueprint $t) { $t->increments('window_id'); });
        Schema::create('pets', function (Blueprint $t) {
            $t->json('customer_locked_fields')->nullable();
            $t->increments('pet_id');
            $t->string('pet_name');
        });
        Schema::create('booking_pets', function (Blueprint $t) {
            $t->increments('booking_pet_id');
            $t->integer('booking_id');
            $t->integer('pet_id');
            $t->integer('groomer_id')->nullable();
            $t->date('pet_queue_date')->nullable();
            $t->integer('pet_queue_number')->nullable();
            $t->text('special_instructions')->nullable();
            $t->string('grooming_preference')->nullable();
            $t->string('grooming_state')->default('not_started');
            $t->dateTime('grooming_start_time')->nullable();
            $t->dateTime('grooming_end_time')->nullable();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->increments('payment_id');
            $t->integer('booking_id');
            $t->string('payment_status');
        });
        Schema::create('services', function (Blueprint $t) {
            $t->increments('service_id');
            $t->string('slug');
            $t->string('service_name');
        });
        Schema::create('booking_services', function (Blueprint $t) {
            $t->increments('booking_service_id');
            $t->integer('booking_id');
            $t->integer('booking_pet_id');
            $t->integer('service_id');
            $t->decimal('price_at_booking');
            $t->decimal('price_min_at_booking')->nullable();
            $t->decimal('price_max_at_booking')->nullable();
        });
        Schema::create('grooming_consents', function (Blueprint $t) {
            $t->id();
            $t->integer('booking_id');
            $t->boolean('grooming_consent');
            $t->boolean('sedation_consent');
            $t->string('signer_name');
            $t->timestamp('signed_at');
            $t->text('signature_strokes');
            $t->text('agreement_snapshot');
        });
        DB::table('bookings')->insert(['booking_id' => 71, 'user_id' => 7, 'booking_reference' => 'BAC-71', 'internal_staff_note' => 'Private']);
        DB::table('pets')->insert(['pet_id' => 4, 'pet_name' => 'Coco']);
        DB::table('booking_pets')->insert(['booking_pet_id' => 8, 'booking_id' => 71, 'pet_id' => 4,
            'groomer_id' => 1, 'special_instructions' => 'Sensitive paws', 'grooming_preference' => 'regular_trim']);
        DB::table('services')->insert(['service_id' => 2, 'slug' => 'regular_dog_grooming', 'service_name' => 'Regular Dog Grooming']);
        DB::table('booking_services')->insert(['booking_id' => 71, 'booking_pet_id' => 8, 'service_id' => 2,
            'price_at_booking' => 650, 'price_min_at_booking' => 650, 'price_max_at_booking' => 650]);
        DB::table('grooming_consents')->insert(['booking_id' => 71, 'grooming_consent' => true, 'sedation_consent' => false,
            'signer_name' => 'Alex Cruz', 'signed_at' => '2026-10-17 08:30:00', 'signature_strokes' => '[]', 'agreement_snapshot' => '{}']);
    }

    private function readVisit(int $customerId)
    {
        $request = Request::create('/api/booking/71', 'GET');
        $request->setUserResolver(fn () => (new User())->forceFill([
            'user_id' => $customerId, 'first_name' => 'Alex', 'last_name' => 'Cruz',
            'phone' => '09171234567', 'email' => 'alex@example.test',
        ]));
        return app(BookingController::class)->show($request, 71);
    }

    public function test_customer_can_reopen_persisted_summary_with_consent_and_services(): void
    {
        $response = $this->readVisit(7);
        $this->assertSame(200, $response->getStatusCode());
        $booking = $response->getData(true)['booking'];
        $this->assertSame('Alex', $booking['owner']['first_name']);
        $this->assertSame('09171234567', $booking['owner']['phone']);
        $this->assertSame('alex@example.test', $booking['owner']['email']);
        $this->assertSame('Coco', $booking['booking_pets'][0]['pet']['pet_name']);
        $this->assertSame('Sensitive paws', $booking['booking_pets'][0]['special_instructions']);
        $this->assertSame('Anna', $booking['booking_pets'][0]['groomer']['first_name']);
        $this->assertSame('Regular Trim', $booking['booking_pets'][0]['grooming_preference_label']);
        $this->assertSame('Regular Dog Grooming', $booking['booking_services'][0]['service']['service_name']);
        $this->assertSame('package', $booking['booking_services'][0]['service']['kind']);
        $this->assertSame('Alex Cruz', $booking['consent']['signer_name']);
        $this->assertFalse($booking['consent']['sedation_consent']);
        $this->assertArrayNotHasKey('signature_strokes', $booking['consent']);
        $this->assertArrayNotHasKey('agreement_snapshot', $booking['consent']);
        $this->assertArrayNotHasKey('internal_staff_note', $booking);
    }

    public function test_another_customer_cannot_read_the_visit(): void
    {
        $this->assertSame(404, $this->readVisit(8)->getStatusCode());
    }

    public function test_unsigned_legacy_visit_does_not_invent_consent(): void
    {
        DB::table('grooming_consents')->delete();
        $this->assertNull($this->readVisit(7)->getData(true)['booking']['consent']);
    }

    public function test_summary_cancellation_eligibility_uses_payment_state_and_count_safeguards(): void
    {
        $this->assertTrue($this->readVisit(7)->getData(true)['booking']['can_cancel']);
        foreach ([['paid' => true], ['cancel_count' => 2], ['status' => 'checked_in'], ['grooming_started_at' => now()]] as $change) {
            DB::table('bookings')->where('booking_id', 71)->update(array_merge([
                'paid' => false, 'cancel_count' => 0, 'status' => 'waiting_to_arrive', 'grooming_started_at' => null,
            ], $change));
            $this->assertFalse($this->readVisit(7)->getData(true)['booking']['can_cancel']);
        }
        DB::table('bookings')->where('booking_id', 71)->update(['grooming_started_at' => null]);
        DB::table('booking_pets')->where('booking_id', 71)->update(['grooming_state' => 'in_progress']);
        $this->assertFalse($this->readVisit(7)->getData(true)['booking']['can_cancel']);
        DB::table('booking_pets')->where('booking_id', 71)->update(['grooming_state' => 'not_started']);
        DB::table('payments')->insert(['booking_id' => 71, 'payment_status' => 'paid']);
        $this->assertFalse($this->readVisit(7)->getData(true)['booking']['can_cancel']);
    }
}
