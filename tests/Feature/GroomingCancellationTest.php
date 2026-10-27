<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\PaymentController;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\User;
use App\Services\GroomingPaymentReadinessService;
use App\Services\OperationalCapacity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingCancellationTest extends TestCase
{
    use \Tests\Support\CreatesGroomingVisitFixture;
    use \Tests\Support\CreatesRegistrationAvailability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGroomingVisitFixture();
        $this->createRegistrationAvailability();
    }

    private function request(string $reason = 'Customer decided not to proceed.'): Request
    {
        $request = Request::create('/cancel', 'POST', ['cancellation_reason' => $reason]);
        $request->setUserResolver(fn () => User::find(1));
        return $request;
    }

    private function reject(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public static function paidActions(): array
    {
        $cases = [];
        foreach (['flag', 'record'] as $paid) {
            foreach (['cancel', 'cancelPet', 'revertCheckIn', 'undoPetStart', 'revertStartGrooming'] as $action) {
                $cases["$paid-$action"] = [$paid, $action];
            }
        }
        return $cases;
    }

    #[DataProvider('paidActions')]
    public function test_payment_blocks_every_cancellation_and_undo(string $paid, string $action): void
    {
        if ($paid === 'flag') DB::table('bookings')->update(['paid' => true]);
        else DB::table('payments')->insert(['booking_id' => 1]);
        $before = DB::table('booking_pets')->get()->toJson();
        $controller = new AdminBookingController;
        $this->reject(fn () => match ($action) {
            'cancel' => $controller->cancel($this->request(), 1),
            'cancelPet' => $controller->cancelPet($this->request(), 1, 2),
            'undoPetStart' => $controller->undoPetStart(1, 1),
            default => $controller->$action(1),
        });
        $this->assertSame($before, DB::table('booking_pets')->get()->toJson());
        $this->assertSame(0, DB::table('customer_notifications')->count());
    }

    public function test_pet_cancellation_preserves_siblings_services_audit_and_customer_message(): void
    {
        (new AdminBookingController)->cancelPet($this->request(), 1, 2);
        $this->assertSame('checked_in', Booking::find(1)->status);
        $pet = BookingPet::find(2);
        $this->assertSame('cancelled', $pet->grooming_state);
        $this->assertNotNull($pet->cancelled_at);
        $this->assertEquals(1, $pet->cancelled_by);
        $this->assertSame('not_started', BookingPet::find(1)->grooming_state);
        $this->assertSame(2, DB::table('booking_services')->count());
        $this->assertEquals(650, DB::table('booking_services')->where('booking_pet_id', 2)->value('price_at_booking'));
        $message = DB::table('customer_notifications')->value('message');
        $this->assertStringContainsString("Mark's grooming was cancelled.", $message);
        $this->assertStringContainsString('Reason: Customer decided not to proceed.', $message);
        $this->assertStringContainsString('Other pets remain active.', $message);
        $this->assertStringNotContainsString('booking is now cancelled', $message);
        $this->assertSame(1, app(OperationalCapacity::class)->groomingPets()->count());
        $this->assertSame(1, app(OperationalCapacity::class)->onSiteGroomingPets()->count());
    }

    public function test_undo_start_only_resets_the_selected_pet_and_preserves_finished_sibling(): void
    {
        DB::table('bookings')->update(['status' => 'in_progress', 'grooming_started_at' => now()]);
        DB::table('booking_pets')->update(['grooming_state' => 'in_progress', 'grooming_start_time' => now()]);
        DB::table('booking_pets')->where('booking_pet_id', 2)->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        $sibling = BookingPet::find(2)->getAttributes();
        (new AdminBookingController)->undoPetStart(1, 1);
        $this->assertSame('not_started', BookingPet::find(1)->grooming_state);
        $this->assertNull(BookingPet::find(1)->grooming_start_time);
        $this->assertSame($sibling, BookingPet::find(2)->getAttributes());
        $this->assertNotNull(Booking::find(1)->grooming_started_at);
        $this->assertSame(0, DB::table('customer_notifications')->count());
        $this->reject(fn () => (new AdminBookingController)->cancel($this->request(), 1));
        $this->assertSame(422, (new AdminBookingController)->revertCheckIn(1)->getStatusCode());
    }

    public function test_undo_check_in_releases_queue_fields_without_cancellation(): void
    {
        (new AdminBookingController)->revertCheckIn(1);
        $booking = Booking::find(1);
        $this->assertSame('waiting_to_arrive', $booking->status);
        $this->assertNull($booking->queue_number);
        $this->assertNull($booking->dropped_off_at);
        $this->assertNull($booking->cancellation_reason);
        $this->assertSame(0, BookingPet::whereNotNull('pet_queue_number')->count());
        $this->assertSame(0, DB::table('customer_notifications')->count());
    }

    public function test_cancelled_sibling_does_not_block_start_finish_or_payment_readiness(): void
    {
        $controller = new AdminBookingController;
        $controller->cancelPet($this->request(), 1, 2);
        $this->assertSame(422, $controller->startPetGrooming(1, 2)->getStatusCode());
        $this->assertSame(200, $controller->startPetGrooming(1, 1)->getStatusCode());
        $this->assertSame(422, $controller->revertCheckIn(1)->getStatusCode());
        $this->reject(fn () => $controller->cancel($this->request(), 1));
        $result = $controller->markPetDone(1, 1)->getData(true);
        $this->assertTrue($result['all_pets_finished']);
        $this->assertSame('for_payment', Booking::find(1)->status);
        $summary = app(GroomingPaymentReadinessService::class)->summarize(Booking::find(1));
        $this->assertTrue($summary['payment_ready']);
        $this->assertSame('850.00', $summary['final_booking_total']);
        $notifications = (new CustomerNotificationController)->index($this->request())->getData(true);
        $this->assertSame(['Hopper'], $notifications['pickup_alert']['pet_names']);
    }

    public function test_cancelling_the_last_waiting_pet_completes_the_finished_sibling_visit(): void
    {
        DB::table('booking_pets')->where('booking_pet_id', 1)->update([
            'grooming_state' => 'finished', 'grooming_start_time' => now()->subHour(), 'grooming_end_time' => now(),
        ]);
        (new AdminBookingController)->cancelPet($this->request(), 1, 2);
        $this->assertSame('for_payment', Booking::find(1)->status);
        $this->assertSame('finished', BookingPet::find(1)->grooming_state);
    }

    public function test_booking_finish_ignores_cancelled_pets(): void
    {
        $controller = new AdminBookingController;
        $controller->cancelPet($this->request(), 1, 2);
        $this->assertSame(200, $controller->startGrooming(1)->getStatusCode());
        $this->assertSame(200, $controller->markDone(1)->getStatusCode());
        $this->assertSame('for_payment', Booking::find(1)->status);
        $this->assertSame('cancelled', BookingPet::find(2)->grooming_state);
    }

    public function test_undo_start_preserves_another_in_progress_pet(): void
    {
        DB::table('bookings')->update(['status' => 'in_progress', 'grooming_started_at' => now()]);
        DB::table('booking_pets')->update(['grooming_state' => 'in_progress', 'grooming_start_time' => now()]);
        (new AdminBookingController)->undoPetStart(1, 1);
        $this->assertSame('in_progress', BookingPet::find(2)->grooming_state);
        $this->assertNotNull(BookingPet::find(2)->grooming_start_time);
        $this->assertEquals(3, Booking::find(1)->queue_number);
        $this->assertSame(1, BookingPet::find(1)->pet_queue_number);
        $this->assertSame(200, (new AdminBookingController)->startPetGrooming(1, 1)->getStatusCode());
    }

    public function test_customer_cancellation_cannot_bypass_a_successful_payment(): void
    {
        DB::table('bookings')->update(['status' => 'waiting_to_arrive', 'booking_date' => now()->toDateString()]);
        DB::table('payments')->insert(['booking_id' => 1]);
        $request = $this->request();
        $request->merge(['booking_id' => 1]);
        $response = (new BookingController)->cancel($request);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('waiting_to_arrive', Booking::find(1)->status);
    }

    public function test_migration_preserves_legacy_cancelled_pet_services_and_reasons(): void
    {
        $migration = require database_path('migrations/2026_10_16_000001_add_grooming_cancellation_audit.php');
        $migration->down();
        DB::table('bookings')->update(['status' => 'cancelled', 'cancellation_reason' => 'Legacy reason']);
        $migration->up();
        $this->assertSame(2, BookingPet::where('grooming_state', 'cancelled')->count());
        $this->assertSame('Legacy reason', BookingPet::find(1)->cancellation_reason);
        $this->assertNull(BookingPet::find(1)->cancelled_at);
        $this->assertSame(2, DB::table('booking_services')->count());
    }

    public function test_all_cancelled_pets_cancel_the_visit_and_remain_in_history(): void
    {
        $controller = new AdminBookingController;
        $controller->cancelPet($this->request(), 1, 1);
        $controller->cancelPet($this->request(), 1, 2);
        $this->assertSame('cancelled', Booking::find(1)->status);
        $history = $controller->archivedIndex(Request::create('/history'))->getData(true)['archived'];
        $this->assertCount(1, $history);
        $this->assertSame('cancelled', $history[0]['status']);
        $this->assertCount(2, $history[0]['pets']);
        $this->assertCount(2, $history[0]['services']);
        $this->assertSame('Gerald Senining', $history[0]['pets'][0]['cancelledBy']);
        $this->assertNotEmpty($history[0]['cancelledAt']);
    }

    public function test_walk_in_owner_cancel_has_no_customer_notification(): void
    {
        DB::table('bookings')->update(['user_id' => null]);
        $response = (new AdminBookingController)->cancel($this->request(), 1)->getData(true);
        $this->assertFalse($response['customer_notified']);
        $this->assertSame(0, DB::table('customer_notifications')->count());
        $this->assertSame(2, BookingPet::where('grooming_state', 'cancelled')->count());
    }

    public function test_reasons_are_required_and_started_or_finished_pets_cannot_be_cancelled_or_undone(): void
    {
        $controller = new AdminBookingController;
        $this->reject(fn () => $controller->cancel($this->request('  '), 1));
        $this->reject(fn () => $controller->cancelPet($this->request(''), 1, 2));
        foreach (['in_progress', 'finished', 'cancelled', 'medical_concern'] as $state) {
            DB::table('booking_pets')->where('booking_pet_id', 1)->update(['grooming_state' => $state]);
            $this->reject(fn () => $controller->cancelPet($this->request(), 1, 1));
            $this->reject(fn () => $controller->undoPetStart(1, 1));
        }
        $this->reject(fn () => $controller->revertStartGrooming(1));
    }

    #[DataProvider('paymentFlows')]
    public function test_cancelled_pet_is_excluded_from_both_payment_flows(string $action): void
    {
        (new AdminBookingController)->cancelPet($this->request(), 1, 2);
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        $summary = app(GroomingPaymentReadinessService::class)->summarize(Booking::find(1));
        $this->assertTrue($summary['payment_ready']);
        $this->assertSame('850.00', $summary['final_booking_total']);
        $this->assertSame('0.00', $summary['pets'][1]['final_pet_charge']);
        DB::table('bookings')->update(['status' => $action === 'store' ? 'for_payment' : 'in_progress']);
        $payload = ['final_price' => 850, 'amount_paid' => 850, 'payment_method' => 'cash',
            'service_prices' => [['booking_service_id' => 1, 'amount' => 850]]];
        $bad = $payload;
        $bad['service_prices'][] = ['booking_service_id' => 2, 'amount' => 650];
        $this->reject(fn () => (new PaymentController)->$action(Request::create('/pay', 'POST', $bad), 1));
        $response = (new PaymentController)->$action(Request::create('/pay', 'POST', $payload), 1)->getData(true);
        $this->assertTrue($response['success']);
        $this->assertEquals(850, DB::table('payments')->value('total_amount'));
        $this->assertEquals(650, DB::table('booking_services')->where('booking_service_id', 2)->value('price_at_booking'));
        $this->assertSame('released', Booking::find(1)->status);
    }

    public static function paymentFlows(): array
    {
        return [['store'], ['payNow']];
    }
}
