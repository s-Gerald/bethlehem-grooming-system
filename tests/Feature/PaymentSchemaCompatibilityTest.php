<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\GatewayPayment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Http;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentSchemaCompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('payments');
        parent::tearDown();
    }

    public function test_payment_model_supports_fresh_install_id_without_optional_processed_by(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('amount_tendered', 10, 2)->nullable();
            $table->decimal('change_amount', 10, 2)->nullable();
            $table->string('payment_method');
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });

        $payment = Payment::create([
            'booking_id' => 99,
            'total_amount' => '0.00',
            'amount_tendered' => '0.00',
            'change_amount' => '0.00',
            'payment_method' => 'others',
            'payment_status' => 'paid',
            'notes' => 'Zero-total completion: no payment required.',
            'paid_at' => now(),
        ]);

        $this->assertSame('id', $payment->getKeyName());
        $this->assertSame(1, $payment->getKey());
        $this->assertSame('0.00', $payment->total_amount);
        $this->assertSame(1, Payment::query()->whereKey(1)->count());
        $this->assertHydratedKeyReadsDoNotHitSchema('id');
    }

    public function test_payment_model_supports_active_payment_id_and_processed_by(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount', 8, 2);
            $table->decimal('amount_tendered', 8, 2)->nullable();
            $table->decimal('change_amount', 8, 2)->nullable();
            $table->string('payment_method');
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->unsignedInteger('processed_by')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        $payment = Payment::create([
            'booking_id' => 100,
            'total_amount' => '125.50',
            'amount_tendered' => '150.00',
            'change_amount' => '24.50',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'processed_by' => 7,
            'paid_at' => now(),
        ]);

        $this->assertSame('payment_id', $payment->getKeyName());
        $this->assertSame(1, $payment->getKey());
        $this->assertSame(7, $payment->processed_by);
        $this->assertHydratedKeyReadsDoNotHitSchema('payment_id');
    }

    private function assertHydratedKeyReadsDoNotHitSchema(string $expectedKey): void
    {
        $payment = Payment::query()->firstOrFail();
        $queryCount = 0;

        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        for ($iteration = 0; $iteration < 25; $iteration++) {
            $this->assertSame($expectedKey, $payment->getKeyName());
            $this->assertSame(1, $payment->getKey());
        }

        $this->assertSame(0, $queryCount, 'Reading a hydrated payment key must not inspect the schema.');
    }

    public function test_obsolete_gateway_routes_are_not_registered(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('online-payment', $route->uri());
            $this->assertStringNotContainsString('paymongo', $route->uri());
        }
    }

    public function test_all_cash_endpoints_reject_unsupported_methods_without_writes(): void
    {
        $this->createHistoricalPaymentSchema();
        foreach (['online', 'qrph', 'gcash', 'card', 'other'] as $method) {
            foreach (['/api/admin/bookings/1/pay', '/api/admin/bookings/1/pay-now'] as $url) {
                $this->postJson($url, ['final_price' => 850, 'amount_paid' => 1000, 'payment_method' => $method])
                    ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
            }
            $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 500, 'amount_paid' => 500, 'payment_method' => $method])
                ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
            $this->postJson('/api/pos/transactions', ['items' => [['item_id' => 10, 'quantity' => 1]], 'amount_tendered' => 100, 'payment_method' => $method])
                ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'paid' => false]);
        Http::assertNothingSent();
    }

    public function test_historical_unresolved_attempts_keep_billing_and_stock_locked(): void
    {
        $this->createHistoricalPaymentSchema();
        $attempt = $this->historicalAttempt('clinic', \App\Models\ClinicAppointment::class, 1);
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 500, 'amount_paid' => 500, 'payment_method' => 'cash'])->assertUnprocessable();
        $this->postJson('/api/admin/clinic-appointments/1/cancel')->assertUnprocessable();
        $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['unit_price' => 1])->assertUnprocessable();
        $this->historicalAttempt('grooming', \App\Models\Booking::class, 1);
        $this->postJson('/api/admin/bookings/1/pay', ['final_price' => 850, 'amount_paid' => 1000, 'payment_method' => 'cash'])->assertUnprocessable();
        DB::table('gateway_stock_reservations')->insert(['gateway_payment_id' => $attempt->id, 'item_id' => 10, 'quantity' => 2]);
        $this->postJson('/api/pos/validate-cart', ['items' => [['item_id' => 10, 'quantity' => 1]]])->assertOk()->assertJsonPath('data.0.saleable_quantity', 1);
        $this->postJson('/api/pos/transactions', ['items' => [['item_id' => 10, 'quantity' => 2]], 'amount_tendered' => 200])->assertUnprocessable();
        $this->postJson('/api/inventory/stock-out', ['items' => [['item_id' => 10, 'quantity' => 2, 'reason' => 'damaged']]])->assertUnprocessable();
        try {
            DB::transaction(fn () => app(\App\Services\GroomingProductSaleService::class)->prepare([['item_id' => 10, 'quantity' => 2]]));
            $this->fail('Historical reserved stock must not be sold as grooming add-ons.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('products', $exception->errors());
        }
        $this->assertDatabaseCount('gateway_stock_reservations', 1);
        $this->assertDatabaseHas('gateway_payments', ['id' => $attempt->id, 'status' => 'pending']);
        $this->assertDatabaseHas('inventory_items', ['item_id' => 10, 'quantity_on_hand' => 3]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_historical_pos_receipts_and_test_collection_exclusion_survive(): void
    {
        $this->createHistoricalPaymentSchema();
        $attempt = $this->historicalAttempt('pos', User::class, 7);
        $attempt->update(['status' => 'paid', 'provider_method' => 'qrph', 'paid_at' => now()]);
        DB::table('pos_transactions')->insert(['pos_id' => 1, 'cashier_id' => 7, 'total_amount' => 160, 'amount_tendered' => 160, 'change_amount' => 0,
            'payment_method' => 'qrph', 'gateway_payment_id' => $attempt->id, 'created_at' => now()]);
        DB::table('pos_transaction_items')->insert(['pos_id' => 1, 'item_id' => 10, 'item_name' => 'Historical Treats', 'unit' => 'pack', 'quantity' => 2, 'price_at_sale' => 80, 'subtotal' => 160]);
        $this->getJson('/api/pos/transactions/1')->assertOk()->assertJsonPath('data.payment_method', 'qrph')->assertJsonPath('data.is_test', true)
            ->assertJsonPath('data.items.0.item_name', 'Historical Treats')->assertJsonPath('data.total_amount', '160.00');
        $this->getJson('/api/admin/transactions?transaction_type=product_sale')->assertOk()
            ->assertJsonPath('transactions.0.paymentMethod', 'qrph')->assertJsonPath('transactions.0.isTest', true)
            ->assertJsonPath('collection_total', 0)->assertJsonPath('test_collection_total', 160);
        $this->getJson('/api/admin/transactions?transaction_type=product_sale&exclude_test=1')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('collection_total', 0);
        $this->assertDatabaseCount('gateway_payments', 1);
        Http::assertNothingSent();
    }

    public function test_shared_gateway_migration_round_trip_preserves_cash_sales(): void
    {
        $this->createHistoricalPaymentSchema();
        DB::table('pos_transactions')->insert(['cashier_id' => 7, 'total_amount' => 80, 'amount_tendered' => 100, 'change_amount' => 20]);
        $migration = require database_path('migrations/2026_10_18_000001_extend_shared_gateway_payments.php');
        $migration->down();
        $this->assertDatabaseHas('pos_transactions', ['total_amount' => 80, 'change_amount' => 20]);
        $migration->up();
        $this->assertDatabaseHas('pos_transactions', ['total_amount' => 80, 'payment_method' => 'cash', 'gateway_payment_id' => null]);
    }

    private function historicalAttempt(string $context, string $type, int $id): GatewayPayment
    {
        return GatewayPayment::create(['provider' => 'paymongo', 'context' => $context, 'payable_type' => $type, 'payable_id' => $id,
            'flow' => 'final', 'reference' => 'HISTORICAL-'.$context, 'amount' => 160, 'currency' => 'PHP', 'status' => 'pending', 'livemode' => false, 'snapshot' => []]);
    }

    private function createHistoricalPaymentSchema(): void
    {
        Http::preventStrayRequests();
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
        DB::table('users')->insert(['user_id' => 7, 'role' => 'admin']);
        DB::table('bookings')->insert(['booking_id' => 1, 'booking_reference' => 'TEST-HISTORICAL', 'status' => 'for_payment']);
        DB::table('pets')->insert(['pet_id' => 4, 'pet_name' => 'Rigby', 'species' => 'Dog', 'size' => 'large']);
        DB::table('booking_pets')->insert(['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 4,
            'registered_size' => 'large', 'confirmed_size' => 'large', 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
        DB::table('services')->insert(['service_id' => 3, 'slug' => 'regular_dog_grooming', 'service_name' => 'Regular Grooming']);
        DB::table('booking_services')->insert(['booking_service_id' => 3, 'booking_id' => 1, 'booking_pet_id' => 2,
            'service_id' => 3, 'price_at_booking' => 850, 'price_min_at_booking' => 850]);
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id(); $table->string('appointment_reference'); $table->string('status');
            $table->decimal('total_amount', 10, 2)->default(0); $table->boolean('paid')->default(false);
            $table->string('payment_method')->nullable(); $table->decimal('cash_received', 10, 2)->nullable();
            $table->unsignedInteger('paid_by_user_id')->nullable(); $table->timestamp('paid_at')->nullable();
            $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('clinic_charges', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id');
            $table->string('kind'); $table->string('description'); $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2); $table->decimal('amount', 10, 2); $table->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->increments('pos_id'); $table->unsignedInteger('cashier_id');
            $table->decimal('total_amount', 10, 2); $table->decimal('amount_tendered', 10, 2);
            $table->decimal('change_amount', 10, 2); $table->text('notes')->nullable(); $table->timestamp('created_at')->nullable();
        });
        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('pos_id'); $table->unsignedInteger('item_id');
            $table->string('item_name'); $table->string('unit'); $table->decimal('quantity', 10, 2);
            $table->decimal('price_at_sale', 10, 2); $table->decimal('subtotal', 10, 2);
        });
        $migration = database_path('migrations/2026_10_18_000001_extend_shared_gateway_payments.php');
        (require $migration)->up();
        DB::table('clinic_appointments')->insert(['id' => 1, 'appointment_reference' => 'CLINIC-TEST', 'status' => 'for_payment', 'total_amount' => 1]);
        DB::table('clinic_charges')->insert(['clinic_appointment_id' => 1, 'kind' => 'service', 'description' => 'Consultation', 'quantity' => 1, 'unit_price' => 500, 'amount' => 500]);
        DB::table('inventory_items')->insert(['item_id' => 10, 'item_name' => 'Dog Treats', 'category' => 'pet_shop', 'unit' => 'pack', 'quantity_on_hand' => 3, 'selling_price' => 80]);
        Sanctum::actingAs(User::findOrFail(7));
    }
}
