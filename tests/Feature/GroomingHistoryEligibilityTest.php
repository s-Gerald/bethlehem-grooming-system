<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Models\Booking;
use App\Models\User;
use App\Support\PetProfileSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingHistoryEligibilityTest extends TestCase
{
    use \Tests\Support\CreatesGroomingVisitFixture;
    use \Tests\Support\CreatesRegistrationAvailability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(16, 30));
        $this->createGroomingVisitFixture();
        $this->createRegistrationAvailability();
        Schema::table('bookings', function (Blueprint $t) {
            $t->unsignedInteger('walkin_id')->nullable();
            $t->string('booking_type')->default('online');
        });
        Schema::create('walkins', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('unregistered_customer_id')->nullable();
            $t->string('fname')->nullable();
            $t->string('lname')->nullable();
        });
        Schema::create('unregistered_customers', function (Blueprint $t) {
            $t->id();
            $t->string('first_name');
            $t->string('last_name');
        });
        Schema::create('grooming_payment_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('payment_id');
            $t->string('item_name');
            $t->decimal('subtotal');
        });
        Schema::create('clinic_appointments', function (Blueprint $t) {
            $t->id();
            $t->string('status')->default('completed');
            $t->boolean('paid')->default(false);
            $t->timestamp('paid_at')->nullable();
            $t->decimal('total_amount')->default(0);
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('pet_id')->nullable();
            $t->unsignedInteger('walkin_id')->nullable();
            $t->string('appointment_reference')->nullable();
        });
        (require database_path('migrations/2026_07_05_100002_create_pos_transactions_table.php'))->up();
        (require database_path('migrations/2026_07_05_100003_create_pos_transaction_items_table.php'))->up();
        (require database_path('migrations/2026_10_07_100000_add_product_snapshot_to_pos_items.php'))->up();
        Schema::table('pets', function (Blueprint $t) {
            $t->unsignedInteger('unregistered_customer_id')->nullable();
        });
        Schema::table('booking_services', fn (Blueprint $t) => $t->string('service_name_snapshot')->nullable());
        DB::table('booking_services')->update(['service_name_snapshot' => 'Recorded grooming']);
        foreach (\App\Models\BookingPet::all() as $pet) {
            $pet->update(['pet_profile_snapshot' => PetProfileSnapshot::registration($pet->pet)]);
        }
        DB::table('bookings')->update(['booking_date' => '2026-11-09']);
    }

    private function request(array $query = []): Request
    {
        $request = Request::create('/history', 'GET', $query);
        $request->setUserResolver(fn () => User::find(1));
        return $request;
    }

    private function history(array $query = []): array
    {
        return app(AdminBookingController::class)->archivedIndex($this->request($query))->getData(true);
    }

    private function transactions(array $query = []): array
    {
        return app(PaymentController::class)->index($this->request(['transaction_type' => 'grooming_payment', ...$query]))->getData(true);
    }

    private function finish(int $id): void
    {
        DB::table('booking_pets')->where('booking_pet_id', $id)->update([
            'grooming_state' => 'in_progress', 'grooming_start_time' => now()->subHour(),
        ]);
        $this->assertSame(200, app(AdminBookingController::class)->markPetDone(1, $id)->getStatusCode());
    }

    private function pay(bool $early): void
    {
        $request = Request::create('/pay', 'POST', ['final_price' => 1500, 'amount_paid' => 1500, 'payment_method' => 'cash',
            'service_prices' => [['booking_service_id' => 1, 'amount' => 850], ['booking_service_id' => 2, 'amount' => 650]]]);
        $response = $early ? app(PaymentController::class)->payNow($request, 1) : app(PaymentController::class)->store($request, 1);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public static function visitFlows(): array
    {
        return ['online finish first' => [false, false], 'walk-in finish first' => [true, false],
            'online pay first' => [false, true], 'walk-in pay first' => [true, true]];
    }

    #[DataProvider('visitFlows')]
    public function test_completed_paid_visits_enter_history_before_pickup(bool $walkin, bool $early): void
    {
        if ($walkin) {
            DB::table('walkins')->insert(['id' => 1, 'user_id' => 1, 'fname' => 'Gerald', 'lname' => 'Senining']);
            DB::table('bookings')->update(['walkin_id' => 1, 'booking_type' => 'walk_in']);
        }
        if ($early) {
            $this->travelTo(now()->setDate(2026, 11, 10)->setTime(14, 0));
            $this->pay(true);
            $this->assertSame(1, $this->transactions()['total']);
            $this->assertSame(0, $this->history()['total']);
            $this->travelTo(now()->setDate(2026, 11, 11)->setTime(16, 30));
        }
        $this->finish(1);
        $this->assertSame(0, $this->history()['total'], 'A partial multi-pet visit is not completed history');
        $this->finish(2);
        if (! $early) {
            $this->assertSame('for_payment', Booking::find(1)->status);
            $this->assertSame(0, $this->history()['total']);
            $this->assertSame(0, $this->transactions()['total']);
            $this->pay(false);
        }
        $this->assertSame('released', Booking::find(1)->status);
        $history = $this->history();
        $this->assertSame(1, $history['total']);
        $this->assertSame(1, $history['archived'][0]['id']);
        $this->assertStringStartsWith('2026-11-11T16:30:00', $history['archived'][0]['historyAtIso']);
        $this->assertSame('2026-11-11', $history['archived'][0]['historyDate']);
        $this->assertNull(Booking::find(1)->archived_at);
        $this->assertSame(0, DB::table('customer_notifications')->where('type', 'picked_up')->count());
        $this->assertSame(1, $this->transactions()['total']);
        $this->assertSame($early ? '2026-11-10' : '2026-11-11', $this->transactions()['transactions'][0]['dateKey']);
        $controller = app(AdminBookingController::class);
        $this->assertCount(1, $controller->index($this->request())->getData(true)['releasedList']);
        $customer = app(BookingController::class)->history($this->request())->getData(true);
        $this->assertSame(1, $customer['history_total']);
        $this->assertCount(1, $customer['bookings'], 'Ready for Pickup stays active');
        $this->assertSame('released', $customer['bookings'][0]['status']);
        $this->assertSame('Hopper', $customer['history'][0]['pets'][0]['pet_name']);
        $this->travelTo(now()->setTime(20, 0));
        $this->assertSame(200, $controller->markPickedUp($this->request(), 1)->getStatusCode());
        $this->assertSame('archived', Booking::find(1)->status);
        $this->assertSame('2026-11-11 20:00:00', Booking::find(1)->archived_at);
        $this->assertCount(0, $controller->index($this->request())->getData(true)['releasedList']);
        $after = $this->history();
        $this->assertSame(1, $after['total']);
        $this->assertSame($history['archived'][0]['historyAtIso'], $after['archived'][0]['historyAtIso']);
        $this->assertSame(1, DB::table('customer_notifications')->where('type', 'picked_up')->count());
        $customer = app(BookingController::class)->history($this->request())->getData(true);
        $this->assertSame(1, $customer['history_total']);
        $this->assertCount(0, $customer['bookings']);
    }

    public function test_released_requires_payment_and_every_applicable_pet_with_a_finish_time(): void
    {
        DB::table('bookings')->update(['status' => 'released', 'grooming_finished_at' => now()]);
        DB::table('booking_pets')->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        $this->assertSame(0, $this->history()['total']);
        DB::table('payments')->insert(['booking_id' => 1, 'payment_status' => 'pending']);
        $this->assertSame(0, $this->history()['total']);
        DB::table('payments')->update(['payment_status' => 'paid']);
        $this->assertSame(1, $this->history()['total'], 'Paid record is authoritative even if the legacy paid flag is false');
        DB::table('booking_pets')->where('booking_pet_id', 2)->update(['grooming_end_time' => null]);
        $this->assertSame(0, $this->history()['total']);
        DB::table('booking_pets')->where('booking_pet_id', 2)->update(['grooming_state' => 'in_progress']);
        $this->assertSame(0, $this->history()['total']);
        DB::table('booking_pets')->where('booking_pet_id', 2)->update(['grooming_state' => 'cancelled']);
        $this->assertSame(1, $this->history()['total'], 'Cancelled sibling does not block the finished pet');
        DB::table('booking_pets')->update(['grooming_state' => 'cancelled']);
        $this->assertSame(0, $this->history()['total'], 'At least one completed applicable pet is required');
        DB::table('booking_pets')->delete();
        $this->assertSame(0, $this->history()['total']);
    }

    public function test_terminal_legacy_and_unpaid_cancelled_outcomes_remain_without_inventing_completion(): void
    {
        DB::table('bookings')->update(['status' => 'cancelled', 'cancelled_at' => '2026-11-10 10:00:00']);
        $record = $this->history()['archived'][0];
        $this->assertSame('cancelled', $record['status']);
        $this->assertNull($record['completedAtIso']);
        $this->assertSame('2026-11-10', $record['historyDate']);
        $this->assertSame(1, $this->history(['date' => '2026-11-10'])['total']);
        $this->assertSame(0, $this->transactions()['total']);
        DB::table('bookings')->update(['status' => 'archived', 'cancelled_at' => null, 'archived_at' => now()]);
        $this->assertSame(1, $this->history()['total'], 'Existing legacy archived records remain readable');
        $this->assertSame('2026-11-09', $this->history()['archived'][0]['historyDate']);
        DB::table('bookings')->update(['cancel_count' => 1, 'cancelled_at' => '2026-11-10 10:00:00',
            'grooming_finished_at' => '2026-11-11 12:00:00']);
        $this->assertSame('2026-11-10', $this->history()['archived'][0]['historyDate'], 'Legacy archived cancellations use their cancellation event');
    }

    public function test_walk_in_owner_identity_and_search_use_the_authoritative_owner_paths(): void
    {
        DB::table('walkins')->insert(['id' => 1, 'fname' => 'John', 'lname' => 'Lennon']);
        DB::table('bookings')->update(['user_id' => null, 'walkin_id' => 1, 'booking_type' => 'walk_in',
            'status' => 'released', 'paid' => true, 'grooming_finished_at' => now()]);
        DB::table('booking_pets')->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        DB::table('payments')->insert(['booking_id' => 1, 'paid_at' => now()]);
        DB::statement('PRAGMA case_sensitive_like = ON');
        foreach (['jOhN', 'LENNON', 'John Lennon'] as $search) {
            $this->assertSame(1, $this->history(['search' => $search])['total'], $search);
            $this->assertSame(1, $this->transactions(['search' => $search])['total'], $search);
        }
        $this->assertSame('John Lennon', $this->transactions()['transactions'][0]['ownerName']);
        $this->assertSame('John Lennon', $this->history()['archived'][0]['ownerName']);
        $this->assertSame(0, DB::table('unregistered_customers')->count(), 'History reads never register the owner');
        DB::table('unregistered_customers')->insert(['id' => 1, 'first_name' => 'Paul', 'last_name' => 'McCartney']);
        DB::table('walkins')->update(['unregistered_customer_id' => 1]);
        foreach (['Paul', 'McCartney', 'Paul McCartney'] as $search) {
            $this->assertSame(1, $this->history(['search' => $search])['total']);
            $this->assertSame(1, $this->transactions(['search' => $search])['total']);
        }
        $this->assertSame('Paul McCartney', $this->transactions()['transactions'][0]['ownerName']);
        $this->assertSame('Paul McCartney', $this->history()['archived'][0]['ownerName']);
        DB::table('walkins')->update(['user_id' => 1]);
        $this->assertSame('Gerald Senining', $this->transactions()['transactions'][0]['ownerName']);
        $this->assertSame('Gerald Senining', $this->history()['archived'][0]['ownerName']);
        foreach (['Gerald', 'Senining', 'GeRaLd SeNiNiNg'] as $search) {
            $this->assertSame(1, $this->history(['search' => $search])['total']);
            $this->assertSame(1, $this->transactions(['search' => $search])['total']);
        }
        DB::table('bookings')->update(['user_id' => 1, 'walkin_id' => null, 'booking_type' => 'online']);
        $this->assertSame(1, $this->history(['search' => 'Gerald Senining'])['total']);
        $this->assertSame(1, $this->transactions(['search' => 'Gerald Senining'])['total']);
        $this->assertSame(1, $this->history(['search' => 'Hopper'])['total']);
    }

    public function test_history_order_and_limits_follow_completion_instead_of_booking_payment_or_pickup(): void
    {
        DB::table('bookings')->update(['status' => 'archived', 'paid' => true,
            'booking_date' => '2026-11-11', 'grooming_finished_at' => '2026-11-11 16:30:00', 'archived_at' => '2026-11-11 20:00:00']);
        DB::table('booking_pets')->update(['grooming_state' => 'finished', 'grooming_end_time' => '2026-11-11 16:30:00']);
        $booking = Booking::find(1)->replicate();
        $booking->fill(['booking_reference' => 'LATER', 'booking_date' => '2026-11-10',
            'grooming_finished_at' => '2026-11-11 17:20:00', 'archived_at' => '2026-11-11 17:45:00'])->save();
        foreach (\App\Models\BookingPet::where('booking_id', 1)->get() as $pet) {
            $pet->replicate()->fill(['booking_id' => $booking->booking_id, 'grooming_end_time' => '2026-11-11 17:20:00'])->save();
        }
        $this->assertSame([2, 1], array_column($this->history()['archived'], 'id'));
        $this->assertSame(2, $this->history(['date' => '2026-11-11'])['total']);
        $customer = app(BookingController::class)->history($this->request(['history_limit' => 1]))->getData(true);
        $this->assertSame(2, $customer['history_total']);
        $this->assertSame(2, $customer['history'][0]['booking_id']);
        $this->assertSame('2026-11-11', $customer['history'][0]['history_date']);
        $booking->update(['grooming_finished_at' => '2026-11-11 16:30:00']);
        $this->assertSame([2, 1], array_column($this->history()['archived'], 'id'), 'Equal timestamps use booking ID');
    }

    public function test_released_history_preserves_snapshots_and_batches_owner_reads(): void
    {
        DB::table('walkins')->insert(['id' => 1, 'fname' => 'John', 'lname' => 'Lennon']);
        DB::table('bookings')->update(['user_id' => null, 'walkin_id' => 1, 'booking_type' => 'walk_in',
            'status' => 'released', 'paid' => true, 'grooming_finished_at' => now()]);
        DB::table('booking_pets')->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        DB::table('payments')->insert(['booking_id' => 1, 'paid_at' => now()]);
        DB::table('pets')->update(['pet_name' => 'Renamed pet']);
        DB::table('services')->update(['service_name' => 'Renamed service']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $record = $this->history()['archived'][0];
        $count = count(DB::getQueryLog());
        $this->assertSame('Hopper', $record['pets'][0]['petName']);
        $this->assertSame('Recorded grooming', $record['services'][0]['serviceName']);
        $this->assertEquals(850, $record['services'][0]['priceAtBooking']);
        $this->assertEquals(1500, $record['payment']['finalPrice']);
        foreach (range(2, 6) as $id) {
            $walkinId = DB::table('walkins')->insertGetId(['fname' => 'John', 'lname' => 'Lennon']);
            $booking = Booking::find(1)->replicate();
            $booking->fill(['booking_reference' => 'VISIT-'.$id, 'walkin_id' => $walkinId])->save();
            $petIds = [];
            foreach (\App\Models\BookingPet::where('booking_id', 1)->get() as $pet) {
                $copy = $pet->replicate()->fill(['booking_id' => $booking->booking_id]);
                $copy->save();
                $petIds[$pet->booking_pet_id] = $copy->booking_pet_id;
            }
            foreach (\App\Models\BookingService::where('booking_id', 1)->get() as $service) {
                $service->replicate()->fill(['booking_id' => $booking->booking_id,
                    'booking_pet_id' => $petIds[$service->booking_pet_id]])->save();
            }
        }
        DB::flushQueryLog();
        $this->assertSame(6, $this->history()['total']);
        $this->assertSame($count, count(DB::getQueryLog()), 'Formatting additional owners and completed pets must not add per-row queries');
        DB::disableQueryLog();
    }
}
