<?php

namespace Tests\Feature;

use App\Models\GatewayPayment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroomingPayMongoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
            $table->timestamp('paid_at');
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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->increments('item_id');
            $table->string('item_name');
            $table->string('category');
            $table->string('unit');
            $table->decimal('unit_cost', 8, 2)->default(0);
            $table->decimal('selling_price', 8, 2)->nullable();
            $table->decimal('quantity_on_hand', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->increments('transaction_id');
            $table->unsignedInteger('item_id');
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable();
            $table->string('reason');
            $table->string('reference_type');
            $table->unsignedInteger('reference_id')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('batch_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('grooming_payment_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedInteger('item_id');
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('price_at_sale', 8, 2);
            $table->decimal('subtotal', 10, 2);
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('role');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        (require database_path('migrations/2026_10_16_120000_create_gateway_payments_table.php'))->up();
        Schema::create('gateway_stock_reservations', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('gateway_payment_id'); $table->unsignedInteger('item_id');
            $table->unsignedInteger('quantity'); $table->unique(['gateway_payment_id', 'item_id']);
        });
        DB::table('users')->insert(['user_id' => 7, 'role' => 'admin']);
        DB::table('bookings')->insert(['booking_id' => 1, 'booking_reference' => 'TEST-ONLINE', 'status' => 'for_payment']);
        DB::table('pets')->insert(['pet_id' => 4, 'pet_name' => 'Rigby', 'species' => 'Dog', 'size' => 'large']);
        DB::table('booking_pets')->insert(['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 4,
            'registered_size' => 'large', 'confirmed_size' => 'large', 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
        DB::table('services')->insert(['service_id' => 3, 'slug' => 'regular_dog_grooming', 'service_name' => 'Regular Grooming']);
        DB::table('booking_services')->insert(['booking_service_id' => 3, 'booking_id' => 1, 'booking_pet_id' => 2,
            'service_id' => 3, 'price_at_booking' => 850, 'price_min_at_booking' => 850]);
        config(['services.paymongo.secret_key' => 'sk_test_fixture', 'services.paymongo.webhook_secret' => 'whsec_fixture',
            'services.paymongo.payment_methods' => ['qrph'], 'services.paymongo.livemode' => false,
            'services.paymongo.return_url' => 'https://bethlehem.example/appointments']);
        Http::preventStrayRequests();
        $this->fakeProvider();
    }

    protected function tearDown(): void
    {
        foreach (['gateway_stock_reservations', 'gateway_payments', 'grooming_payment_products', 'inventory_transactions', 'inventory_items',
            'notifications', 'payments', 'booking_services', 'services', 'booking_pets', 'pets', 'bookings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_cash_still_settles_without_paymongo_configuration(): void
    {
        config(['services.paymongo.secret_key' => null]);
        $this->staff();
        $this->postJson('/api/admin/bookings/1/pay', [
            'final_price' => 850, 'amount_paid' => 1000, 'payment_method' => 'cash',
            'service_prices' => [['booking_service_id' => 3, 'amount' => 850]],
        ])->assertOk()->assertJsonPath('change', '150.00');
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'paid' => true, 'status' => 'released']);
        Http::assertNothingSent();
    }

    public function test_checkout_uses_server_total_and_does_not_create_a_paid_record(): void
    {
        $this->checkout(['final_price' => 1, 'amount_paid' => 999])->assertOk()->assertJsonPath('online_payment.amount', '850.00');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
            && $request['data']['attributes']['payment_method_types'] === ['qrph']
            && $request['data']['attributes']['line_items'][0]['amount'] === 85000
            && $request['data']['attributes']['line_items'][0]['currency'] === 'PHP'
            && $request['data']['attributes']['pass_on_fees'] === false
            && str_starts_with($request['data']['attributes']['reference_number'], 'BTH-'));
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'paid' => false]);
        $this->assertSame(7, GatewayPayment::first()->initiated_by);
        $this->assertSame('850.00', GatewayPayment::first()->snapshot['grooming_total']);
        $this->assertSame(['qrph'], GatewayPayment::first()->snapshot['payment_methods']);
    }

    public function test_active_checkout_is_reused_and_its_prices_cannot_be_changed_or_paid_in_cash(): void
    {
        $this->checkout()->assertOk();
        $this->checkout(['service_prices' => [['booking_service_id' => 3, 'amount' => 1000]]])->assertOk();
        Http::assertSentCount(1);
        $this->assertSame('850.00', GatewayPayment::first()->amount);
        $this->postJson('/api/admin/bookings/1/pay', ['final_price' => 850, 'amount_paid' => 850])->assertUnprocessable();
        $this->assertSame(0, Payment::count());
    }

    public function test_cancel_confirms_expiration_before_unlocking(): void
    {
        $this->checkout()->assertOk();
        $this->fakeProvider('expired');
        $this->deleteJson('/api/admin/bookings/1/online-payment')->assertOk()->assertJsonPath('online_payment.status', 'cancelled');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_fixture/expire');
        $this->assertSame(0, Payment::count());
    }

    public function test_refused_expiration_keeps_the_payment_locked(): void
    {
        $this->checkout()->assertOk();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*/expire' => Http::response([], 400), 'api.paymongo.com/*' => Http::response([
            'data' => ['id' => 'cs_fixture', 'attributes' => ['status' => 'active', 'payments' => [
                ['attributes' => ['status' => 'processing']],
            ]]],
        ])]);
        $this->deleteJson('/api/admin/bookings/1/online-payment')->assertUnprocessable();
        $this->assertSame('pending', GatewayPayment::first()->status);
    }

    public function test_signed_webhook_is_public_and_final_settlement_is_idempotent(): void
    {
        $this->checkout()->assertOk();
        $this->app['auth']->forgetGuards();
        $event = $this->event();
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $event['data']['id'] = 'evt_redelivery';
        $this->webhook($event)->assertOk();
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertDatabaseHas('payments', ['payment_method' => 'online', 'payment_provider' => 'paymongo', 'provider_method' => 'qrph']);
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'status' => 'released', 'paid' => true]);
        $this->assertSame('paid', GatewayPayment::first()->status);
        $this->staff();
        $this->getJson('/api/admin/bookings/1/online-payment')->assertOk()
            ->assertJsonPath('payment.payment_method_label', 'Online Payment')->assertJsonPath('payment.amount_paid', null);
    }

    public function test_early_payment_keeps_unfinished_visit_in_its_workflow(): void
    {
        DB::table('bookings')->update(['status' => 'in_progress']);
        DB::table('booking_pets')->update(['grooming_state' => 'in_progress', 'grooming_end_time' => null]);
        $this->checkout(['flow' => 'early'])->assertOk();
        Http::assertSent(fn ($request) => $request['data']['attributes']['payment_method_types'] === ['qrph']);
        $this->webhook($this->event())->assertOk();
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'paid' => true, 'status' => 'in_progress']);
        $this->assertSame('850.00', GatewayPayment::first()->snapshot['summary']['pets'][0]['final_pet_charge']);
        $this->assertSame('850.00', GatewayPayment::first()->snapshot['grooming_total']);
    }

    public function test_early_payment_releases_if_pets_finish_while_checkout_is_pending(): void
    {
        DB::table('bookings')->update(['status' => 'in_progress']);
        $this->checkout(['flow' => 'early'])->assertOk();
        $this->webhook($this->event())->assertOk();
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'paid' => true, 'status' => 'released']);
    }

    public function test_invalid_signature_wrong_mode_and_mismatched_amount_do_not_settle(): void
    {
        $this->checkout()->assertOk();
        $this->webhook($this->event(), 't='.time().',te=invalid,li=')->assertUnauthorized();
        $this->webhook($this->event(live: true))->assertUnprocessable();
        $this->webhook($this->event(84999))->assertUnprocessable();
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', GatewayPayment::first()->status);
    }

    public function test_live_signature_is_rejected_even_with_the_current_envelope(): void
    {
        $this->checkout()->assertOk();
        config(['services.paymongo.livemode' => true]);
        GatewayPayment::first()->update(['livemode' => true]);
        $event = ['id' => 'evt_current', 'event_type' => 'send.webhook', 'data' => $this->event(live: true)['data']['attributes']];
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $time = time();
        $this->webhook($event, 't='.$time.',te=,li='.hash_hmac('sha256', $time.'.'.$body, 'whsec_fixture'))->assertUnauthorized();
        $this->assertSame('pending', GatewayPayment::first()->status);
    }

    public function test_unpaid_provider_payment_cannot_settle(): void
    {
        $this->checkout()->assertOk();
        $event = $this->event();
        $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['status'] = 'pending';
        $this->webhook($event)->assertUnprocessable();
        $this->assertSame(0, Payment::count());
    }

    public function test_already_paid_booking_cannot_create_another_checkout(): void
    {
        DB::table('bookings')->update(['paid' => true]);
        $this->checkout()->assertConflict();
        $this->assertSame(0, GatewayPayment::count());
        Http::assertNothingSent();
    }

    public function test_api_rejection_or_ambiguous_failure_never_marks_payment_paid(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.paymongo.com/*' => Http::response([], 422)]);
        $this->checkout()->assertStatus(502);
        $this->assertSame('failed', GatewayPayment::first()->status);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.paymongo.com/*' => Http::response([], 503)]);
        $this->checkout()->assertStatus(502);
        $this->assertSame('pending', GatewayPayment::latest('id')->first()->status);
        $this->checkout()->assertStatus(502);
        Http::assertSentCount(1);
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'paid' => false]);
    }

    public function test_products_are_frozen_and_sold_only_once_after_payment(): void
    {
        $this->addProduct();
        $this->checkout(['products' => [['item_id' => 10, 'quantity' => 2]]])->assertOk();
        $this->assertSame('850.00', GatewayPayment::first()->snapshot['grooming_total']);
        $this->assertSame('1010.00', GatewayPayment::first()->snapshot['total']);
        $this->assertEquals(3, DB::table('inventory_items')->value('quantity_on_hand'));
        $this->assertSame(0, DB::table('grooming_payment_products')->count());
        DB::table('inventory_items')->update(['selling_price' => 200, 'item_name' => 'Renamed']);
        $event = $this->event(101000);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $this->assertEquals(1, DB::table('inventory_items')->value('quantity_on_hand'));
        $this->assertSame(1, DB::table('grooming_payment_products')->count());
        $this->assertSame(1, DB::table('inventory_transactions')->count());
        $this->assertDatabaseHas('grooming_payment_products', ['item_name' => 'Dog Treats', 'price_at_sale' => 80, 'subtotal' => 160]);
    }

    public function test_stock_conflict_keeps_successful_charge_and_flags_reconciliation(): void
    {
        $this->addProduct();
        $this->checkout(['products' => [['item_id' => 10, 'quantity' => 2]]])->assertOk();
        DB::table('inventory_items')->update(['quantity_on_hand' => 0]);
        $this->webhook($this->event(101000))->assertOk();
        $this->webhook($this->event(101000))->assertOk();
        $this->assertSame('paid', GatewayPayment::first()->status);
        $this->assertSame('product_stock_conflict', GatewayPayment::first()->reconciliation_reason);
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('bookings', ['paid' => false]);
        $this->assertDatabaseHas('gateway_stock_reservations', ['quantity' => 2]);
        $this->assertEquals(0, DB::table('inventory_items')->value('quantity_on_hand'));
        $this->assertSame(0, DB::table('inventory_transactions')->count());
        $this->assertSame(0, DB::table('grooming_payment_products')->count());
        $this->getJson('/api/admin/bookings/1/online-payment')->assertJsonPath('online_payment.reconciliation_reason', 'product_stock_conflict')
            ->assertJsonMissingPath('payment');
    }

    public function test_missing_credentials_and_unauthorized_requests_fail_safely(): void
    {
        $this->getJson('/api/admin/bookings/1/online-payment')->assertUnauthorized();
        config(['services.paymongo.secret_key' => null]);
        $this->checkout()->assertUnprocessable()->assertJsonPath('errors.payment.0', 'Online payment is not configured.');
        Http::assertNothingSent();
    }

    public function test_online_checkout_preserves_service_pet_and_product_validation(): void
    {
        $this->checkout(['service_prices' => [['booking_service_id' => 3, 'amount' => 1350.01]]])->assertUnprocessable();
        $this->checkout(['pet_sizes' => [['booking_pet_id' => 999, 'size' => 'large']]])->assertUnprocessable();
        $this->addProduct();
        $this->checkout(['products' => [['item_id' => 10, 'quantity' => 4]]])->assertUnprocessable();
        $this->assertEquals(850, DB::table('booking_services')->value('price_at_booking'));
        $this->assertSame(0, GatewayPayment::count());
        Http::assertNothingSent();
    }

    public function test_pending_early_checkout_blocks_grooming_cancellations(): void
    {
        DB::table('bookings')->update(['status' => 'checked_in']);
        $this->checkout(['flow' => 'early'])->assertOk();
        $this->postJson('/api/admin/bookings/1/cancel', ['cancellation_reason' => 'Test'])
            ->assertUnprocessable()->assertJsonPath('errors.payment.0', 'Cancel the active online payment first.');
        $this->postJson('/api/admin/bookings/1/pets/2/cancel', ['cancellation_reason' => 'Test'])
            ->assertUnprocessable()->assertJsonPath('errors.payment.0', 'Cancel the active online payment first.');
    }

    public function test_checkout_and_currency_mismatches_do_not_settle_and_paid_checkout_cannot_cancel(): void
    {
        $this->checkout()->assertOk();
        $event = $this->event();
        $event['data']['attributes']['data']['id'] = 'cs_other';
        $this->webhook($event)->assertUnprocessable();
        $event = $this->event();
        $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['currency'] = 'USD';
        $this->webhook($event)->assertUnprocessable();
        $this->assertSame(0, Payment::count());
        $this->webhook($this->event())->assertOk();
        $this->deleteJson('/api/admin/bookings/1/online-payment')->assertConflict();
    }

    public function test_delayed_paid_event_for_already_paid_visit_records_a_reconciliation_condition(): void
    {
        $this->checkout()->assertOk();
        DB::table('bookings')->update(['paid' => true]);
        $this->webhook($this->event())->assertOk();
        $this->assertSame('paid', GatewayPayment::first()->status);
        $this->assertSame('booking_already_paid', GatewayPayment::first()->reconciliation_reason);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_webhook_requires_environment_reference_metadata_and_provider_identifiers(): void
    {
        $this->checkout()->assertOk();
        foreach (['mode', 'reference', 'context', 'payable', 'event_id', 'payment_id', 'resource_type', 'currency'] as $case) {
            $event = $this->event();
            $session = &$event['data']['attributes']['data'];
            if ($case === 'mode') $session['attributes']['payments'][0]['attributes']['livemode'] = true;
            if ($case === 'reference') $session['attributes']['reference_number'] = 'BTH-other';
            if ($case === 'context') unset($session['attributes']['metadata']['context']);
            if ($case === 'payable') $session['attributes']['metadata']['booking_id'] = '99';
            if ($case === 'event_id') unset($event['data']['id']);
            if ($case === 'payment_id') $session['attributes']['payments'][0]['id'] = '';
            if ($case === 'resource_type') $session['type'] = 'payment';
            if ($case === 'currency') $session['attributes']['payments'][0]['attributes']['currency'] = 'USD';
            $this->webhook($event)->assertUnprocessable();
            unset($session);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('gateway_payments', ['status' => 'pending']);
    }

    public function test_old_grooming_metadata_remains_compatible_without_weakening_new_attempts(): void
    {
        $this->checkout()->assertOk();
        $attempt = GatewayPayment::first();
        $snapshot = $attempt->snapshot;
        unset($snapshot['metadata_version']);
        $attempt->update(['snapshot' => $snapshot]);
        $event = $this->event();
        unset($event['data']['attributes']['data']['attributes']['metadata']['context']);
        unset($event['data']['attributes']['data']['attributes']['reference_number']);
        $this->webhook($event)->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_abbreviated_official_webhook_uses_the_signed_event_environment(): void
    {
        $this->checkout()->assertOk();
        $event = $this->event();
        unset($event['data']['attributes']['data']['attributes']['livemode']);
        unset($event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['livemode']);
        $this->webhook($event)->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_grooming_addons_are_reserved_until_confirmed_cancellation(): void
    {
        $this->addProduct();
        $this->checkout(['products' => [['item_id' => 10, 'quantity' => 2]]])->assertOk();
        $this->assertDatabaseHas('gateway_stock_reservations', ['quantity' => 2]);
        $this->deleteJson('/api/admin/bookings/1/online-payment')->assertUnprocessable();
        $this->assertDatabaseCount('gateway_stock_reservations', 1);
        $this->fakeProvider('expired');
        $this->deleteJson('/api/admin/bookings/1/online-payment')->assertOk();
        $this->assertDatabaseCount('gateway_stock_reservations', 0);
        $this->assertDatabaseHas('inventory_items', ['quantity_on_hand' => 3]);
    }

    public function test_checkout_creation_reuses_the_committed_attempt_during_an_overlapping_request(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel(), 'Provider creation must not hold financial locks.');
            $this->checkout()->assertStatus(502);
            $this->assertDatabaseCount('gateway_payments', 1);
            return Http::response(['data' => ['id' => 'cs_fixture', 'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_fixture', 'livemode' => false]]]);
        });
        $this->checkout()->assertOk();
        Http::assertSentCount(1);
    }

    public function test_webhook_winning_a_cancellation_race_cannot_be_overwritten(): void
    {
        $this->checkout()->assertOk();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel(), 'Cancellation must not hold financial locks.');
            $this->webhook($this->event())->assertOk();
            return Http::response(['data' => ['id' => 'cs_fixture', 'type' => 'checkout_session',
                'attributes' => ['status' => 'expired', 'livemode' => false]]]);
        });
        $this->deleteJson('/api/admin/bookings/1/online-payment')->assertConflict();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('gateway_payments', ['status' => 'paid']);
    }

    public function test_delayed_payment_after_expiry_is_retained_for_reconciliation(): void
    {
        $this->checkout()->assertOk();
        $this->fakeProvider('expired');
        $this->getJson('/api/admin/bookings/1/online-payment')->assertOk()->assertJsonPath('online_payment.status', 'expired');
        $this->webhook($this->event())->assertOk();
        $this->assertDatabaseHas('gateway_payments', ['status' => 'paid', 'reconciliation_reason' => 'checkout_closed_before_confirmation']);
        $this->assertDatabaseCount('payments', 0);
        $this->postJson('/api/admin/bookings/1/pay', ['final_price' => 850, 'amount_paid' => 850])->assertUnprocessable();
    }

    public function test_stale_signature_cannot_settle(): void
    {
        $this->checkout()->assertOk();
        $event = $this->event(); $time = time() - 3600;
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $this->webhook($event, 't='.$time.',te='.hash_hmac('sha256', $time.'.'.$body, 'whsec_fixture').',li=')->assertUnauthorized();
        $this->assertDatabaseCount('payments', 0);
    }

    public static function concurrentCompetitors(): array
    {
        // Final settlement releases the visit; existing direct endpoints reject
        // its released state before checking the paid flag.
        return [['webhook', 200], ['cash', 422], ['qrph', 422]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('concurrentCompetitors')]
    public function test_concurrent_settlement_records_one_sale_and_stock_deduction(string $competitor, int $expected): void
    {
        $this->addProduct();
        $this->checkout(['products' => [['item_id' => 10, 'quantity' => 2]]])->assertOk();
        $directory = sys_get_temp_dir().'/bethlehem-paymongo-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/payment.sqlite';
        $worker = <<<'PHP'
            require 'tests/bootstrap.php';
            $database = $argv[1]; $directory = realpath(dirname($database));
            if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                || !preg_match('/\Abethlehem-paymongo-[a-f0-9]{12}\z/', basename($directory))
                || basename($database) !== 'payment.sqlite' || !is_file($database)) throw new RuntimeException('Owned test database required.');
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                'database.connections.sqlite.busy_timeout' => 5000, 'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
                'services.paymongo.webhook_secret' => 'whsec_fixture', 'services.paymongo.livemode' => false]);
            Illuminate\Support\Facades\DB::purge();
            $role = $argv[2]; $competitor = $argv[3]; $contended = false;
            $wait = function ($name) use ($directory) {
                $deadline = microtime(true) + 5;
                while (!is_file($directory.'/'.$name) && microtime(true) < $deadline) {clearstatcache(); usleep(1000);}
                if (!is_file($directory.'/'.$name)) throw new RuntimeException('Settlement barrier timed out.');
            };
            $db = Illuminate\Support\Facades\DB::connection();
            $db->beforeStartingTransaction(function () use ($role, $database, $directory, $wait, &$contended) {
                if ($role !== 'second') return;
                $wait('locked');
                $probe = new PDO('sqlite:'.$database); $probe->exec('PRAGMA busy_timeout=0');
                try {
                    $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK');
                    throw new RuntimeException('The webhook must still own the writer lock.');
                } catch (PDOException $error) {
                    if (($error->errorInfo[1] ?? null) !== 5) throw $error;
                    $contended = true;
                }
                file_put_contents($directory.'/attempted', '1');
            });
            $db->listen(function ($query) use ($role, $directory, $wait) {
                if ($role === 'first' && str_starts_with(strtolower($query->sql), 'insert into "payments"')) {
                    file_put_contents($directory.'/locked', '1'); $wait('attempted');
                }
            });
            if ($role === 'first' || $competitor === 'webhook') {
                $body = file_get_contents($directory.'/event.json'); $time = time();
                $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
                    'HTTP_PAYMONGO_SIGNATURE' => 't='.$time.',te='.hash_hmac('sha256', $time.'.'.$body, 'whsec_fixture').',li=',
                ], $body);
                $response = app(App\Http\Controllers\PayMongoWebhookController::class)($request, app(App\Services\PayMongoService::class));
            } else {
                $request = Illuminate\Http\Request::create('/payment', 'POST', [
                    'final_price' => 1010, 'amount_paid' => 1010, 'payment_method' => $competitor,
                    'qr_settings_version' => 1, 'qr_verified' => true, 'payment_source' => 'GCash', 'external_reference' => 'RACE000001',
                ]);
                $request->setUserResolver(fn () => App\Models\User::findOrFail(7));
                $response = app(App\Http\Controllers\PaymentController::class)->store($request, 1);
            }
            echo json_encode(['status' => $response->getStatusCode(), 'contended' => $contended]);
            PHP;
        $processes = [];
        try {
            file_put_contents($directory.'/event.json', json_encode($this->event(101000), JSON_UNESCAPED_SLASHES));
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            foreach (['first', 'second'] as $role) {
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, $role, $competitor], base_path(), [
                    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => storage_path('framework/testing-config-unused.php'),
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                ]);
                $process->setTimeout(15); $process->start(); $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertTrue($results[1]['contended']);
            $this->assertSame(200, $results[0]['status']); $this->assertSame($expected, $results[1]['status']);
            $pdo = new \PDO('sqlite:'.$database);
            foreach (['payments', 'grooming_payment_products', 'inventory_transactions', 'notifications'] as $table) {
                $this->assertEquals(1, $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(), $table);
            }
            $this->assertEquals(0, $pdo->query('SELECT COUNT(*) FROM gateway_stock_reservations')->fetchColumn());
            $this->assertEquals(1, $pdo->query('SELECT quantity_on_hand FROM inventory_items')->fetchColumn());
            $pdo = null;
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function test_qrph_checkout_rejects_a_signed_payment_from_another_channel(): void
    {
        $this->addProduct();
        $this->checkout(['products' => [['item_id' => 10, 'quantity' => 2]]])->assertOk();
        $event = $this->event(101000);
        $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['source']['type'] = 'gcash';
        $this->webhook($event)->assertUnprocessable();
        $this->assertDatabaseHas('gateway_payments', ['status' => 'pending', 'provider_payment_id' => null]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('gateway_stock_reservations', 1);
    }

    public function test_historical_grooming_pending_payment_keeps_its_original_channel(): void
    {
        $this->checkout()->assertOk();
        $attempt = GatewayPayment::firstOrFail();
        $snapshot = $attempt->snapshot;
        unset($snapshot['payment_methods']);
        $attempt->update(['snapshot' => $snapshot]);
        $event = $this->event();
        $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['source']['type'] = 'card';
        $this->webhook($event)->assertOk();
        $this->assertDatabaseHas('payments', ['payment_method' => 'online', 'payment_provider' => 'paymongo', 'provider_method' => 'card']);
        $this->getJson('/api/admin/bookings/1/online-payment')->assertOk()->assertJsonPath('payment.payment_method_label', 'Online Payment');
    }

    private function addProduct(): void
    {
        DB::table('inventory_items')->insert(['item_id' => 10, 'item_name' => 'Dog Treats', 'category' => 'pet_shop',
            'unit' => 'pack', 'quantity_on_hand' => 3, 'selling_price' => 80]);
    }

    private function staff(): void
    {
        Sanctum::actingAs(User::findOrFail(7));
    }

    private function fakeProvider(string $status = 'active'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_fixture', 'type' => 'checkout_session',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_fixture', 'status' => $status, 'livemode' => false]]])]);
    }

    private function checkout(array $overrides = [])
    {
        $this->staff();
        return $this->postJson('/api/admin/bookings/1/online-payment', array_replace([
            'flow' => 'final', 'payment_method' => 'online',
            'service_prices' => [['booking_service_id' => 3, 'amount' => 850]],
            'pet_sizes' => [['booking_pet_id' => 2, 'size' => 'large']],
        ], $overrides));
    }

    private function event(int $amount = 85000, bool $live = false): array
    {
        $attempt = GatewayPayment::firstOrFail();
        return ['data' => ['id' => 'evt_fixture', 'type' => 'event', 'attributes' => [
            'type' => 'checkout_session.payment.paid', 'livemode' => $live,
            'data' => ['id' => 'cs_fixture', 'type' => 'checkout_session', 'attributes' => [
                'livemode' => $live, 'reference_number' => $attempt->reference, 'metadata' => app(\App\Services\OnlinePaymentService::class)->metadata($attempt),
                'payments' => [['id' => 'pay_fixture', 'type' => 'payment', 'attributes' => [
                    'status' => 'paid', 'amount' => $amount, 'currency' => 'PHP', 'livemode' => $live,
                    'paid_at' => time(), 'source' => ['type' => 'qrph'],
                ]]],
            ]],
        ]]];
    }

    private function webhook(array $event, ?string $signature = null)
    {
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature ??= 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture').',li=';
        return $this->call('POST', '/api/paymongo/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $body);
    }
}
