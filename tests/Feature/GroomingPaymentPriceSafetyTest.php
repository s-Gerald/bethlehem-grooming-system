<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingPaymentPriceSafetyTest extends TestCase
{
    private bool $fixtureDatabaseVerified = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Support/QrPaymentMysqlHarness.php';
        \Tests\Support\assertQrPaymentTestDatabase();
        $this->fixtureDatabaseVerified = true;
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('grooming_finished_at')->nullable();
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('pet_name');
            $table->string('species');
            $table->string('size');
            $table->text('clinic_verified_fields')->nullable();
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->string('registered_size');
            $table->string('confirmed_size')->nullable();
            $table->string('grooming_state');
            $table->timestamp('grooming_end_time')->nullable();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('slug');
            $table->string('service_name');
        });
        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id');
            $table->decimal('price_at_booking', 8, 2);
            $table->decimal('price_min_at_booking', 8, 2)->nullable();
            $table->decimal('price_max_at_booking', 8, 2)->nullable();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('amount_tendered', 10, 2);
            $table->decimal('change_amount', 10, 2);
            $table->string('payment_method');
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type');
            $table->unsignedInteger('booking_id');
            $table->text('message');
            $table->boolean('is_read');
            $table->timestamp('created_at');
        });
    }

    protected function tearDown(): void
    {
        if ($this->fixtureDatabaseVerified) {
            \Tests\Support\assertQrPaymentTestDatabase();
            foreach (['qr_payments', 'qr_payment_settings', 'notifications', 'payments', 'booking_services', 'services', 'booking_pets', 'pets', 'bookings'] as $table) {
                Schema::dropIfExists($table);
            }
        }
        parent::tearDown();
    }

    #[DataProvider('plusServices')]
    public function test_both_payment_flows_enforce_caps_and_allow_review_thresholds(
        string $action, string $slug, string $size, int $minimum, ?int $threshold, int $maximum,
    ): void {
        $this->seedService($slug, $size, $minimum);
        foreach ([$minimum - 0.01, $maximum + 0.01] as $invalid) {
            $message = $invalid < $minimum
                ? 'Enter an amount of at least ₱'.number_format($minimum).'.'
                : 'Enter ₱'.number_format($maximum).' or less for this service.';
            $this->assertRejected($action, $invalid, $message);
            $this->assertEquals($minimum, DB::table('booking_services')->value('price_at_booking'));
            $this->assertSame(0, Payment::count());
            $this->assertSame(0, DB::table('notifications')->count());
        }

        $validAmounts = array_unique([$minimum, $minimum + 0.01, $threshold ?? $minimum, ($threshold ?? $minimum) + 0.01, $maximum]);
        foreach ($validAmounts as $valid) {
            $response = $this->submit($action, $valid)->getData(true);
            $this->assertTrue($response['success']);
            $this->assertEquals($valid, $response['final_price']);
            $this->assertEquals($valid + 5, $response['amount_paid']);
            $this->assertEquals(5, $response['change']);
            $this->assertSame('Grooming price checked.', Payment::first()->notes);
            $this->assertEquals($minimum, DB::table('booking_services')->value('price_min_at_booking'));
            $this->assertNull(DB::table('booking_services')->value('price_max_at_booking'));
            if ($threshold !== null && $valid >= $threshold) {
                $comparison = $valid == $threshold ? 'at' : 'above';
                $this->assertSame("This amount is {$comparison} the Extra Large starting price of ₱"
                    .number_format($threshold).". Confirm the pet's size and final charge.",
                    $response['service_price_warnings'][0]['message']);
            } else {
                $this->assertSame([], $response['service_price_warnings']);
            }
            DB::table('payments')->delete();
            DB::table('bookings')->update(['paid' => false, 'status' => $action === 'store' ? 'for_payment' : 'in_progress']);
        }
    }

    public static function plusServices(): array
    {
        $services = [
            ['partial_grooming', 'large', 600, 700, 1100],
            ['partial_grooming', 'extra_large', 700, null, 1200],
            ['regular_dog_grooming', 'large', 850, 1050, 1350],
            ['regular_dog_grooming', 'extra_large', 1050, null, 1550],
            ['deluxe_dog_grooming', 'large', 1000, 1200, 1500],
            ['deluxe_dog_grooming', 'extra_large', 1200, null, 1700],
            ['bath_and_go', 'large', 650, 750, 1150],
            ['bath_and_go', 'extra_large', 750, null, 1250],
            ['ear_cleaning', 'small', 150, null, 350],
            ['tooth_brushing', 'small', 100, null, 300],
        ];
        $cases = [];
        foreach (['store', 'payNow'] as $action) {
            foreach ($services as $service) {
                $cases[$action.' '.$service[0].' '.$service[1]] = [$action, ...$service];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidCurrency')]
    public function test_manipulated_currency_is_rejected_before_payment(string $action, mixed $amount): void
    {
        $this->seedService('regular_dog_grooming', 'large', 850);
        try {
            $this->submit($action, $amount, 850);
            $this->fail('Malformed currency must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('service_prices.0.amount', $exception->errors());
        }
        $this->assertSame(0, Payment::count());
        $this->assertEquals(850, DB::table('booking_services')->value('price_at_booking'));
    }

    public static function invalidCurrency(): array
    {
        $cases = [];
        foreach (['store', 'payNow'] as $action) {
            foreach (['', null, '-1', '850.001', '1e5', '1E3', '+850', '850 ', 'NaN'] as $index => $amount) {
                $cases[$action.' '.$index] = [$action, $amount];
            }
        }

        return $cases;
    }

    public function test_fixed_prices_and_actual_ranges_remain_enforced(): void
    {
        $this->seedService('facial_trimming', 'small', 150, fixed: true);
        foreach (['store', 'payNow'] as $action) {
            $this->assertRejected($action, 151, 'This service has a fixed price of ₱150.');
        }
        DB::table('services')->update(['slug' => 'nail_clipping']);
        DB::table('booking_services')->update(['price_at_booking' => 50, 'price_min_at_booking' => 50, 'price_max_at_booking' => 100]);
        foreach (['store', 'payNow'] as $action) {
            foreach ([49, 101] as $invalid) {
                $this->assertRejected($action, $invalid, 'Enter an amount from ₱50 to ₱100.');
            }
        }
        $this->assertTrue($this->submit('payNow', 100)->getData(true)['success']);
    }

    public function test_size_changes_recalculate_caps_and_preserve_original_price_snapshots(): void
    {
        $this->seedService('regular_dog_grooming', 'large', 850);
        $sizes = [['booking_pet_id' => 2, 'size' => 'extra_large']];
        $this->assertRejected('payNow', 1550.01, 'Enter ₱1,550 or less for this service.', $sizes);
        $this->assertTrue($this->submit('payNow', 1550, sizes: $sizes)->getData(true)['success']);
        $this->assertSame('extra_large', DB::table('booking_pets')->value('confirmed_size'));
        $this->assertEquals(850, DB::table('booking_services')->value('price_min_at_booking'));
        $this->assertEquals(1550, DB::table('booking_services')->value('price_at_booking'));
    }

    public function test_legacy_plus_prices_without_saved_bounds_still_use_menu_safety_caps(): void
    {
        $this->seedService('regular_dog_grooming', 'large', 850);
        DB::table('booking_services')->update(['price_min_at_booking' => null]);
        foreach (['store', 'payNow'] as $action) {
            $this->assertRejected($action, 1350.01, 'Enter ₱1,350 or less for this service.');
        }
        $this->assertTrue($this->submit('store', 1350)->getData(true)['success']);
        $this->assertNull(DB::table('booking_services')->value('price_min_at_booking'));
        $this->assertNull(DB::table('booking_services')->value('price_max_at_booking'));
    }

    public function test_invalid_second_service_rolls_back_earlier_price_changes(): void
    {
        $this->seedService('regular_dog_grooming', 'large', 850);
        DB::table('services')->insert(['service_id' => 4, 'slug' => 'ear_cleaning', 'service_name' => 'Ear Cleaning']);
        DB::table('booking_services')->insert([
            'booking_service_id' => 5, 'booking_id' => 1, 'booking_pet_id' => 2,
            'service_id' => 4, 'price_at_booking' => 150, 'price_min_at_booking' => 150,
        ]);
        $request = Request::create('/pay-now', 'POST', [
            'final_price' => 1400, 'amount_paid' => 1400, 'payment_method' => 'cash',
            'service_prices' => [
                ['booking_service_id' => 3, 'amount' => 1000],
                ['booking_service_id' => 5, 'amount' => 400],
            ],
        ]);
        DB::table('bookings')->update(['status' => 'in_progress']);
        try {
            (new PaymentController)->payNow($request, 1);
            $this->fail('The second service must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('Enter ₱350 or less for this service.', $exception->errors()['service_prices'][0]);
        }
        $this->assertEquals(850, DB::table('booking_services')->where('booking_service_id', 3)->value('price_at_booking'));
        $this->assertSame(0, Payment::count());
    }

    public function test_cash_pay_now_preserves_unfinished_workflow_and_prevents_duplicate_payment(): void
    {
        $this->seedService('regular_dog_grooming', 'large', 850);
        DB::table('booking_pets')->update(['grooming_state' => 'in_progress', 'grooming_end_time' => null]);
        $response = $this->submit('payNow', 850)->getData(true);
        $this->assertTrue($response['success']);
        $this->assertSame('cash', $response['payment_method']);
        $this->assertFalse($response['all_pets_finished']);
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'paid' => true, 'status' => 'in_progress']);
        $this->assertDatabaseHas('payments', ['payment_method' => 'cash', 'total_amount' => 850, 'amount_tendered' => 855, 'change_amount' => 5]);
        $this->assertSame(409, $this->submit('payNow', 850)->getStatusCode());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    private function seedService(string $slug, string $size, int $minimum, bool $fixed = false): void
    {
        DB::table('bookings')->insert(['booking_id' => 1, 'booking_reference' => 'TEST-PRICE', 'status' => 'for_payment']);
        DB::table('pets')->insert(['pet_id' => 4, 'pet_name' => 'Rigby', 'species' => 'Dog', 'size' => $size]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 4,
            'registered_size' => $size, 'confirmed_size' => $size,
            'grooming_state' => 'finished', 'grooming_end_time' => now(),
        ]);
        DB::table('services')->insert(['service_id' => 3, 'slug' => $slug, 'service_name' => $slug]);
        DB::table('booking_services')->insert([
            'booking_service_id' => 3, 'booking_id' => 1, 'booking_pet_id' => 2,
            'service_id' => 3, 'price_at_booking' => $minimum,
            'price_min_at_booking' => $fixed ? null : $minimum,
        ]);
    }

    public function test_verified_qr_final_payment_reuses_settlement_and_normalizes_reference(): void
    {
        \Carbon\Carbon::withTestNow('2026-10-23 15:20:30', function () {
            $this->configureQr();
            $this->seedService('regular_dog_grooming', 'large', 850);
            $response = $this->submitQr('store', [
                'transaction_at' => '2026-10-22T13:14', 'verified_at' => '2000-01-01 00:00:00',
            ])->getData(true);
            $this->assertTrue($response['success']);
            $this->assertSame('QR Ph', $response['payment_method_label']);
            $this->assertSame('123456AB', $response['qr_payment']['external_reference']);
            $this->assertEquals(7, $response['qr_payment']['verified_by']);
            $this->assertSame('2026-10-22 13:14:00', $response['qr_payment']['transaction_at']);
            $this->assertSame('2026-10-23 15:20:30', $response['qr_payment']['verified_at']);
            $this->assertDatabaseHas('payments', ['payment_method' => 'qrph', 'total_amount' => 850, 'change_amount' => 0]);
            $this->assertDatabaseHas('payments', ['paid_at' => '2026-10-23 15:20:30']);
            $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'paid' => true, 'status' => 'released']);
            $this->assertDatabaseCount('notifications', 1);
            $this->assertEquals(850, Payment::realCollections()->sum('total_amount'));
        });
    }

    public function test_qr_early_payment_preserves_unfinished_visit_and_rejects_repeated_submission(): void
    {
        $this->configureQr();
        $this->seedService('regular_dog_grooming', 'large', 850);
        DB::table('bookings')->update(['status' => 'in_progress']);
        DB::table('booking_pets')->update(['grooming_state' => 'in_progress', 'grooming_end_time' => null]);
        $response = $this->submitQr('payNow')->getData(true);
        $this->assertFalse($response['all_pets_finished']);
        $this->assertDatabaseHas('bookings', ['paid' => true, 'status' => 'in_progress']);
        $this->assertSame(409, $this->submitQr('payNow')->getStatusCode());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('qr_payments', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_qr_validation_rolls_back_prices_payments_and_notifications_and_allows_retry(): void
    {
        $this->configureQr();
        $this->seedService('regular_dog_grooming', 'large', 850);
        $cases = [
            ['amount_paid' => 851], ['amount_paid' => 849], ['amount_paid' => null],
            ['qr_verified' => false], ['qr_settings_version' => 99],
            ['external_reference' => '!!!'], ['external_reference' => ''],
            ['external_reference' => 'AB@123456'], ['transaction_at' => '2099-01-01T12:00'],
            ['transaction_at' => null], ['verification_source' => 'customer_screenshot'],
        ];
        foreach ($cases as $override) {
            try {
                $this->submitQr('store', $override);
                $this->fail('Unverified or mismatched payment must be rejected: '.json_encode($override));
            } catch (ValidationException) {
                $this->assertDatabaseCount('payments', 0);
                $this->assertDatabaseCount('qr_payments', 0);
                $this->assertDatabaseCount('notifications', 0);
                $this->assertDatabaseHas('bookings', ['paid' => false, 'status' => 'for_payment']);
            }
        }
        $this->assertTrue($this->submitQr('store')->getData(true)['success']);
    }

    public function test_duplicate_normalized_qr_reference_does_not_create_another_collection(): void
    {
        $this->configureQr();
        $this->seedService('regular_dog_grooming', 'large', 850);
        $this->submitQr('store');
        // Another unpaid visit with the same reference must not settle.
        DB::table('bookings')->insert(['booking_id' => 9, 'booking_reference' => 'TEST-SECOND', 'status' => 'for_payment']);
        DB::table('booking_pets')->update(['booking_id' => 9]);
        DB::table('booking_services')->update(['booking_id' => 9]);
        try {
            $this->submitQr('store', ['external_reference' => '1234 56ab'], bookingId: 9);
            $this->fail('Duplicate reference should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('external_reference', $exception->errors());
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('qr_payments', 1);
        $this->assertDatabaseHas('bookings', ['booking_id' => 9, 'paid' => false]);
    }

    public function test_disabled_qr_and_unauthorized_actor_cannot_settle(): void
    {
        $this->configureQr();
        $this->seedService('regular_dog_grooming', 'large', 850);
        DB::table('qr_payment_settings')->update(['enabled' => false]);
        try {
            $this->submitQr('store');
            $this->fail('Disabled QR must be rejected.');
        } catch (ValidationException) {}
        DB::table('qr_payment_settings')->update(['enabled' => true]);
        try {
            $this->submitQr('store', role: 'customer');
            $this->fail('Customers cannot verify payments.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_missing_reference_exception_requires_admin_reason_and_additional_acknowledgment(): void
    {
        $this->configureQr();
        $this->seedService('regular_dog_grooming', 'large', 850);
        $exception = ['external_reference' => null, 'reference_unavailable_reason' => 'Official receiving history has no reference; matched the account credit.', 'exception_verified' => true];
        foreach ([['exception_verified' => false], ['reference_unavailable_reason' => 'short'],
            ['reference_unavailable_reason' => str_repeat('x', 19).' ']] as $invalid) {
            try {
                $this->submitQr('store', array_replace($exception, $invalid));
                $this->fail('Incomplete exception must be rejected.');
            } catch (ValidationException) {}
        }
        try {
            $this->submitQr('store', $exception, role: 'staff');
            $this->fail('Only administrators can approve missing references.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $response = $this->submitQr('store', $exception)->getData(true);
        $this->assertNull($response['qr_payment']['external_reference']);
        $this->assertSame($exception['reference_unavailable_reason'], $response['qr_payment']['reference_unavailable_reason']);
    }

    public function test_database_uniqueness_stops_duplicate_external_references_even_without_a_precheck(): void
    {
        $this->configureQr();
        $this->seedService('regular_dog_grooming', 'large', 850);
        $this->submitQr('store');
        $duplicate = \App\Models\QrPayment::firstOrFail()->replicate();
        $duplicate->payable_id = 99;
        try {
            $duplicate->save();
            $this->fail('The database must arbitrate duplicate-reference races.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->assertDatabaseCount('qr_payments', 1);
            $this->assertDatabaseCount('payments', 1);
        }
    }

    private function configureQr(): void
    {
        (require database_path('migrations/2026_10_23_000001_create_qr_payments.php'))->up();
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('qr-payments/code.png', 'fixture');
        DB::table('qr_payment_settings')->where('id', 1)->update([
            'enabled' => true, 'merchant_name' => 'Bethlehem', 'provider_name' => 'Receiving bank',
            'image_path' => 'qr-payments/code.png', 'image_hash' => hash('sha256', 'fixture'),
        ]);
    }

    private function submitQr(string $action, array $override = [], string $role = 'admin', int $bookingId = 1)
    {
        $request = Request::create('/payment', 'POST', array_replace([
            'final_price' => 850, 'amount_paid' => 850, 'payment_method' => 'qrph',
            'service_prices' => [['booking_service_id' => 3, 'amount' => 850]],
            'qr_settings_version' => 1, 'qr_verified' => true,
            'external_reference' => '1234-56 ab', 'transaction_at' => now()->format('Y-m-d\TH:i'),
            'verification_source' => 'receiving_history',
        ], $override));
        $actor = new \App\Models\User;
        $actor->forceFill(['user_id' => 7, 'role' => $role, 'first_name' => 'Staff', 'last_name' => 'Verifier',
            'staff_type' => 'grooming', 'staff_subrole' => 'grooming_receptionist']);
        $request->setUserResolver(fn () => $actor);
        return (new PaymentController)->{$action}($request, $bookingId);
    }

    private function submit(string $action, mixed $amount, ?float $total = null, array $sizes = [])
    {
        DB::table('bookings')->update(['status' => $action === 'store' ? 'for_payment' : 'in_progress']);
        $total ??= (float) $amount;
        $request = Request::create('/payment', 'POST', [
            'final_price' => $total, 'amount_paid' => $total + 5,
            'payment_method' => 'cash', 'notes' => 'Grooming price checked.',
            'service_prices' => [['booking_service_id' => 3, 'amount' => $amount]],
            'pet_sizes' => $sizes,
        ]);

        return (new PaymentController)->{$action}($request, 1);
    }

    private function assertRejected(string $action, float $amount, string $message, array $sizes = []): void
    {
        try {
            $this->submit($action, $amount, sizes: $sizes);
            $this->fail('An invalid service price was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()['service_prices'][0]);
        }
    }
}
