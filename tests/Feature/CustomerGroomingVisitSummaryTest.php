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
        Schema::create('bookings', function (Blueprint $t) {
            $t->increments('booking_id');
            $t->integer('user_id');
            $t->string('booking_reference');
            $t->string('status')->default('waiting_to_arrive');
            $t->integer('window_id')->nullable();
            $t->text('internal_staff_note')->nullable();
        });
        Schema::create('time_windows', function (Blueprint $t) { $t->increments('window_id'); });
        Schema::create('pets', function (Blueprint $t) {
            $t->increments('pet_id');
            $t->string('pet_name');
        });
        Schema::create('booking_pets', function (Blueprint $t) {
            $t->increments('booking_pet_id');
            $t->integer('booking_id');
            $t->integer('pet_id');
            $t->integer('groomer_id')->nullable();
            $t->text('special_instructions')->nullable();
            $t->string('grooming_preference')->nullable();
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
        $request->setUserResolver(fn () => (new User())->forceFill(['user_id' => $customerId]));
        return app(BookingController::class)->show($request, 71);
    }

    public function test_customer_can_reopen_persisted_summary_with_consent_and_services(): void
    {
        $response = $this->readVisit(7);
        $this->assertSame(200, $response->getStatusCode());
        $booking = $response->getData(true)['booking'];
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
}
