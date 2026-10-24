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

class SharedPayMongoTest extends TestCase
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
        DB::table('users')->insert(['user_id' => 7, 'role' => 'admin']);
        DB::table('bookings')->insert(['booking_id' => 1, 'booking_reference' => 'TEST-ONLINE', 'status' => 'for_payment']);
        DB::table('pets')->insert(['pet_id' => 4, 'pet_name' => 'Rigby', 'species' => 'Dog', 'size' => 'large']);
        DB::table('booking_pets')->insert(['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 4,
            'registered_size' => 'large', 'confirmed_size' => 'large', 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
        DB::table('services')->insert(['service_id' => 3, 'slug' => 'regular_dog_grooming', 'service_name' => 'Regular Grooming']);
        DB::table('booking_services')->insert(['booking_service_id' => 3, 'booking_id' => 1, 'booking_pet_id' => 2,
            'service_id' => 3, 'price_at_booking' => 850, 'price_min_at_booking' => 850]);
        config(['services.paymongo.secret_key' => 'sk_test_fixture', 'services.paymongo.webhook_secret' => 'whsec_fixture',
            'services.paymongo.payment_methods' => ['qrph'], 'services.paymongo.livemode' => false, 'services.paymongo.return_url' => 'https://bethlehem.example/pages/admin/appointments.html']);
        Http::preventStrayRequests();
        $this->fakeProvider();
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

    protected function tearDown(): void
    {
        foreach (['gateway_stock_reservations', 'clinic_charges', 'clinic_appointments', 'pos_transaction_items', 'pos_transactions', 'gateway_payments', 'grooming_payment_products', 'inventory_transactions', 'inventory_items',
            'notifications', 'payments', 'booking_services', 'services', 'booking_pets', 'pets', 'bookings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }


    public function test_clinic_checkout_uses_finalized_database_charges_and_blocks_direct_online_settlement(): void
    {
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['payment_method' => 'gcash', 'total_amount' => 500])->assertUnprocessable();
        $this->postJson('/api/admin/clinic-appointments/1/online-payment', ['total_amount' => 1])->assertOk()->assertJsonPath('online_payment.amount', '500.00');
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'paid' => false]);
        $this->assertDatabaseCount('payments', 0);
        Http::assertSent(fn ($r) => $r['data']['attributes']['line_items'][0]['amount'] === 50000
            && $r['data']['attributes']['payment_method_types'] === ['qrph']
            && $r['data']['attributes']['success_url'] === 'https://bethlehem.example/pages/admin/clinic.html?gateway_payment=1&payment_context=clinic&payment_return=success&payable=1'
            && $r['data']['attributes']['cancel_url'] === 'https://bethlehem.example/pages/admin/clinic.html?gateway_payment=1&payment_context=clinic&payment_return=cancel&payable=1');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['payment_method' => 'cash', 'total_amount' => 500, 'amount_paid' => 500])->assertUnprocessable();
    }

    public static function deploymentEnvironments(): array
    {
        return [
            'explicit HTTPS return overrides localhost' => ['http://127.0.0.1:8000', 'http://127.0.0.1:5173',
                'https://bethlehem.example/pages/admin/appointments.html', 'https://bethlehem.example'],
            'frontend fallback preserves subdirectory' => ['https://api.example', 'https://bethlehem.example/system/',
                null, 'https://bethlehem.example/system'],
            'app fallback' => ['https://bethlehem.example', null, null, 'https://bethlehem.example'],
            'separate localhost development' => ['http://127.0.0.1:8000', 'http://127.0.0.1:5173',
                null, 'http://127.0.0.1:5173'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deploymentEnvironments')]
    public function test_environment_return_urls_and_signature_survive_cached_configuration(
        string $appUrl, ?string $frontendUrl, ?string $returnUrl, string $expectedBase,
    ): void {
        $directory = sys_get_temp_dir().'/bethlehem-payment-config-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $cache = $directory.'/config.php';
        // Child processes receive only fixtures, never credentials inherited from the local .env.
        $environment = array_fill_keys(array_keys(array_merge(getenv(), $_SERVER, $_ENV)), false);
        foreach (['SystemRoot', 'PATH', 'TEMP', 'TMP'] as $name) {
            if (getenv($name) !== false) $environment[$name] = getenv($name);
        }
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $cache,
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ]);
        $worker = <<<'PHP'
            require 'tests/bootstrap.php';
            $app = require 'bootstrap/app.php';
            $app->addAbsoluteCachePathPrefix($argv[1]);
            $app->useEnvironmentPath($argv[1]);
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if ($argv[2] === 'prepare') {
                // The same serialized configuration format consumed after artisan config:cache.
                file_put_contents($app->getCachedConfigPath(), '<?php return '.var_export(config()->all(), true).';');
            }
            $provider = app(App\Services\PayMongoService::class);
            $provider->assertConfigured();
            $urls = [];
            foreach (['grooming', 'clinic', 'pos'] as $context) {
                $attempt = new App\Models\GatewayPayment(['context' => $context, 'payable_id' => 9]);
                $attempt->id = 7;
                foreach (['success', 'cancel'] as $outcome) {
                    $urls[$context][$outcome] = app(App\Services\OnlinePaymentService::class)->returnUrl($attempt, $outcome);
                }
            }
            $body = '{"fixture":true}'; $timestamp = time();
            $signature = 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture');
            echo json_encode(['cached' => $app->configurationIsCached(), 'urls' => $urls,
                'methods' => $provider->paymentMethods(), 'live' => $provider->livemode(),
                'valid_signature' => $provider->validSignature($body, $signature),
                'tampered_signature' => $provider->validSignature($body.' ', $signature)], JSON_THROW_ON_ERROR);
            PHP;
        try {
            foreach (['prepare', 'cached'] as $mode) {
                $fixtures = $mode === 'prepare' ? [
                    'APP_URL' => $appUrl, 'FRONTEND_URL' => $frontendUrl ?? false, 'PAYMONGO_RETURN_URL' => $returnUrl ?? false,
                    'PAYMONGO_SECRET_KEY' => 'sk_test_fixture', 'PAYMONGO_WEBHOOK_SECRET' => 'whsec_fixture',
                    'PAYMONGO_LIVE_MODE' => 'false',
                ] : [];
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $directory, $mode],
                    base_path(), array_replace($environment, $fixtures));
                $process->setTimeout(15);
                $process->run();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($mode === 'cached', $result['cached']);
                $this->assertSame(['qrph'], $result['methods']);
                $this->assertFalse($result['live']);
                $this->assertTrue($result['valid_signature']);
                $this->assertFalse($result['tampered_signature']);
                foreach (['grooming' => '/pages/admin/appointments.html', 'clinic' => '/pages/admin/clinic.html',
                    'pos' => '/pages/admin/inventory/inventory.html'] as $context => $page) {
                    foreach (['success', 'cancel'] as $outcome) {
                        $this->assertSame($expectedBase.$page.'?gateway_payment=7&payment_context='.$context
                            .'&payment_return='.$outcome.'&payable=9'.($context === 'pos' ? '#sell-product' : ''),
                            $result['urls'][$context][$outcome]);
                    }
                }
            }
        } finally {
            if (is_file($cache)) unlink($cache);
            rmdir($directory);
        }
    }

    public function test_clinic_verified_webhook_settles_once_with_actual_method(): void
    {
        $this->clinicCheckout();
        $event = $this->event();
        $this->webhook($event)->assertOk(); $this->webhook($event)->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'paid' => true, 'status' => 'completed', 'payment_method' => 'online', 'gateway_payment_id' => 1]);
        $this->assertDatabaseCount('gateway_payments', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('gateway_payments', ['provider' => 'paymongo', 'provider_method' => 'qrph']);
        $this->getJson('/api/admin/clinic-appointments/1/online-payment')->assertOk()
            ->assertJsonPath('appointment.payment_method_label', 'Online Payment');
    }

    public function test_historical_grooming_payment_keeps_recorded_environment_after_configuration_changes(): void
    {
        $this->postJson('/api/admin/bookings/1/online-payment', [
            'flow' => 'final', 'payment_method' => 'online',
            'service_prices' => [['booking_service_id' => 3, 'amount' => 850]],
            'pet_sizes' => [['booking_pet_id' => 2, 'size' => 'large']],
        ])->assertOk();
        $this->webhook($this->event(85000))->assertOk();
        config(['services.paymongo.livemode' => true]);

        $this->getJson('/api/admin/bookings/1/online-payment')->assertOk()
            ->assertJsonPath('payment.is_test', true)
            ->assertJsonPath('payment.payment_method_label', 'Online Payment');
        $this->getJson('/api/admin/transactions?transaction_type=grooming_payment')->assertOk()
            ->assertJsonPath('transactions.0.isTest', true)
            ->assertJsonPath('transactions.0.paymentMethodLabel', 'Online Payment')
            ->assertJsonPath('collection_total', 0)->assertJsonPath('test_collection_total', 850);
        $this->assertFalse(GatewayPayment::firstOrFail()->livemode);
    }

    public function test_historical_clinic_payment_keeps_recorded_environment_after_configuration_changes(): void
    {
        $this->clinicCheckout();
        $this->webhook($this->event())->assertOk();
        config(['services.paymongo.livemode' => true]);

        $this->getJson('/api/admin/clinic-appointments/1/online-payment')->assertOk()
            ->assertJsonPath('appointment.is_test', true)
            ->assertJsonPath('appointment.payment_method_label', 'Online Payment');
        $this->getJson('/api/admin/transactions?transaction_type=clinic_payment')->assertOk()
            ->assertJsonPath('transactions.0.isTest', true)
            ->assertJsonPath('transactions.0.paymentMethodLabel', 'Online Payment')
            ->assertJsonPath('collection_total', 0)->assertJsonPath('test_collection_total', 500);
        $this->assertFalse(GatewayPayment::firstOrFail()->livemode);
    }

    public function test_clinic_checkout_preserves_whole_quantities_and_finalized_fractional_charges(): void
    {
        DB::table('clinic_charges')->where('id', 1)->update(['quantity' => 2, 'amount' => 1000]);
        DB::table('clinic_charges')->insert(['clinic_appointment_id' => 1, 'kind' => 'medicine', 'description' => 'Medicine dose', 'quantity' => 0.5, 'unit_price' => 100, 'amount' => 50]);
        $this->clinicCheckout();
        Http::assertSent(fn ($request) => $request['data']['attributes']['line_items'][0]['quantity'] === 2
            && $request['data']['attributes']['line_items'][0]['amount'] === 50000
            && $request['data']['attributes']['line_items'][1]['quantity'] === 1
            && $request['data']['attributes']['line_items'][1]['amount'] === 5000);
        $this->assertDatabaseHas('gateway_payments', ['amount' => 1050, 'livemode' => false]);
    }

    public function test_clinic_rejects_wrong_amount_metadata_signature_and_live_webhooks(): void
    {
        $this->clinicCheckout();
        $this->webhook($this->event(1))->assertUnprocessable();
        $this->webhook($this->event(50000, true))->assertUnprocessable();
        $this->webhook($this->event(), 'invalid')->assertUnauthorized();
        $event = $this->event(); $event['data']['attributes']['data']['attributes']['metadata']['clinic_appointment_id'] = '99';
        $this->webhook($event)->assertUnprocessable();
        $this->assertDatabaseHas('clinic_appointments', ['paid' => false]);
    }

    public function test_current_send_webhook_envelope_settles_in_test_mode(): void
    {
        $this->clinicCheckout();
        $this->webhook(['id' => 'evt_current', 'event_type' => 'send.webhook', 'data' => $this->event()['data']['attributes']])->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['paid' => true, 'status' => 'completed']);
    }

    public function test_pos_online_checkout_with_hosted_qrph_reserves_stock_and_only_signed_webhook_creates_sale(): void
    {
        $this->posCheckout('online')->assertOk()->assertJsonPath('online_payment.amount', '160.00');
        Http::assertSent(fn ($r) => $r['data']['attributes']['payment_method_types'] === ['qrph']
            && $r['data']['attributes']['line_items'][0]['quantity'] === 2
            && $r['data']['attributes']['line_items'][0]['amount'] === 8000
            && $r['data']['attributes']['success_url'] === 'https://bethlehem.example/pages/admin/inventory/inventory.html?gateway_payment=1&payment_context=pos&payment_return=success&payable=7#sell-product'
            && $r['data']['attributes']['cancel_url'] === 'https://bethlehem.example/pages/admin/inventory/inventory.html?gateway_payment=1&payment_context=pos&payment_return=cancel&payable=7#sell-product');
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseHas('inventory_items', ['item_id' => 10, 'quantity_on_hand' => 3]);
        $this->assertDatabaseHas('gateway_stock_reservations', ['item_id' => 10, 'quantity' => 2]);
        $this->postJson('/api/pos/transactions', ['items' => [['item_id' => 10, 'quantity' => 2]], 'amount_tendered' => 160])->assertUnprocessable();
        $this->webhook($this->event(16000))->assertOk(); $this->webhook($this->event(16000))->assertOk();
        $this->assertDatabaseCount('pos_transactions', 1); $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseHas('inventory_items', ['item_id' => 10, 'quantity_on_hand' => 1]);
        $this->assertDatabaseCount('gateway_stock_reservations', 0);
        $this->getJson('/api/pos/transactions/1')->assertOk()->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.payment_method_label', 'Online Payment')->assertJsonPath('data.is_test', true);
    }

    public function test_pos_online_methods_duplicate_requests_and_frozen_prices(): void
    {
        $this->posCheckout()->assertOk(); $this->posCheckout()->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['data']['attributes']['payment_method_types'] === ['qrph']);
        DB::table('inventory_items')->where('item_id', 10)->update(['selling_price' => 999]);
        $event = $this->event(16000);
        $this->webhook($event)->assertOk();
        $this->assertDatabaseHas('pos_transaction_items', ['price_at_sale' => 80, 'subtotal' => 160]);
        $repeated = $this->posCheckout();
        $this->assertSame(200, $repeated->status(), $repeated->getContent());
        $repeated->assertJsonPath('online_payment.status', 'paid');
        $this->assertDatabaseCount('pos_transactions', 1);
    }

    public function test_cancellation_does_not_release_stock_until_provider_confirms_expiry(): void
    {
        $this->posCheckout()->assertOk();
        $this->deleteJson('/api/pos/online-payment/1')->assertUnprocessable();
        $this->assertDatabaseCount('gateway_stock_reservations', 1);
        $this->fakeProvider('expired');
        $this->deleteJson('/api/pos/online-payment/1')->assertOk()->assertJsonPath('online_payment.status', 'cancelled');
        $this->assertDatabaseCount('gateway_stock_reservations', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_return_and_provider_paid_status_cannot_settle_without_webhook(): void
    {
        $this->posCheckout()->assertOk();
        $event = $this->event(16000);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => $event['data']['attributes']['data']])]);
        $this->getJson('/api/pos/online-payment/1?return=success')->assertOk()->assertJsonPath('online_payment.status', 'pending');
        $this->assertDatabaseCount('pos_transactions', 0); $this->assertDatabaseCount('gateway_stock_reservations', 1);
        $this->webhook($event)->assertOk();
    }

    public function test_pending_stock_is_protected_from_manual_stock_out_and_other_cashiers(): void
    {
        $this->posCheckout()->assertOk();
        $this->postJson('/api/inventory/stock-out', ['items' => [['item_id' => 10, 'quantity' => 2, 'reason' => 'damaged']]])->assertUnprocessable();
        DB::table('users')->insert(['user_id' => 8, 'role' => 'admin']); Sanctum::actingAs(User::findOrFail(8));
        $this->posCheckout('online', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')->assertUnprocessable();
        $this->getJson('/api/pos/online-payment/1')->assertForbidden();
        $this->assertDatabaseCount('gateway_payments', 1);
    }

    public function test_checkout_creation_failure_releases_but_timeout_retains_reservation(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); Http::fake(['api.paymongo.com/*' => Http::response([], 422)]);
        $this->posCheckout()->assertStatus(502);
        $this->assertDatabaseCount('gateway_stock_reservations', 0);
        Http::swap(new \Illuminate\Http\Client\Factory); Http::fake(['api.paymongo.com/*' => Http::failedConnection()]);
        $this->posCheckout('online', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')->assertStatus(502);
        $this->assertDatabaseCount('gateway_stock_reservations', 1);
        $this->posCheckout('online', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')->assertOk()->assertJsonPath('online_payment.status', 'pending');
    }

    public function test_live_configuration_is_rejected_even_with_live_keys(): void
    {
        config(['services.paymongo.livemode' => true, 'services.paymongo.secret_key' => 'sk_live_fixture']);
        $this->posCheckout()->assertUnprocessable(); Http::assertNothingSent();
    }

    public function test_test_sales_remain_in_history_but_are_excluded_from_financial_reporting(): void
    {
        $this->posCheckout()->assertOk(); $this->webhook($this->event(16000))->assertOk();
        config(['services.paymongo.livemode' => true]);
        $this->getJson('/api/pos/transactions/1')->assertOk()
            ->assertJsonPath('data.is_test', true)->assertJsonPath('data.payment_method_label', 'Online Payment');
        $this->getJson('/api/admin/transactions?transaction_type=product_sale')->assertOk()
            ->assertJsonPath('transactions.0.paymentMethod', 'online')->assertJsonPath('transactions.0.isTest', true)
            ->assertJsonPath('transactions.0.paymentMethodLabel', 'Online Payment')
            ->assertJsonPath('transactions.0.receipt.is_test', true)
            ->assertJsonPath('collection_total', 0)->assertJsonPath('test_collection_total', 160);
        $this->getJson('/api/admin/transactions?transaction_type=product_sale&exclude_test=1')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('collection_total', 0);
    }

    public function test_processing_payments_keep_reservations_even_when_session_claims_expired(): void
    {
        $this->posCheckout()->assertOk();
        foreach (['paid', 'pending', 'processing'] as $status) {
            $session = $this->event(16000)['data']['attributes']['data'];
            $session['attributes']['status'] = 'expired';
            $session['attributes']['payments'][0]['attributes']['status'] = $status;
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['api.paymongo.com/*' => Http::response(['data' => $session])]);
            $this->deleteJson('/api/pos/online-payment/1')->assertUnprocessable();
            $this->getJson('/api/pos/online-payment/1')->assertOk()->assertJsonPath('online_payment.status', 'pending');
            $this->assertDatabaseCount('gateway_stock_reservations', 1);
        }
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_failed_payment_event_is_not_terminal_until_checkout_is_confirmed_expired(): void
    {
        $this->posCheckout()->assertOk();
        $event = $this->event(16000); $event['data']['attributes']['type'] = 'payment.failed';
        $this->webhook($event)->assertOk();
        $this->assertDatabaseHas('gateway_payments', ['status' => 'pending']);
        $this->assertDatabaseCount('gateway_stock_reservations', 1);
        $this->fakeProvider('expired');
        $this->getJson('/api/pos/online-payment/1')->assertOk()->assertJsonPath('online_payment.status', 'expired');
        $this->assertDatabaseCount('gateway_stock_reservations', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_clinic_cannot_change_billing_or_cancel_a_pending_checkout(): void
    {
        $this->clinicCheckout();
        $this->postJson('/api/admin/clinic-appointments/1/cancel')->assertUnprocessable();
        $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['unit_price' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('clinic_charges', ['amount' => 500]);
        $this->assertDatabaseHas('clinic_appointments', ['status' => 'for_payment', 'paid' => false]);
    }

    public function test_pos_rejects_changed_prices_invalid_quantities_and_duplicate_idempotency_ownership(): void
    {
        $payload = ['payment_method' => 'online', 'request_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        $this->postJson('/api/pos/online-payment', [...$payload, 'items' => [['item_id' => 10, 'quantity' => 1.5]]])->assertUnprocessable();
        $this->postJson('/api/pos/online-payment', [...$payload, 'items' => [['item_id' => 10, 'quantity' => 1, 'expected_price' => 1]]])->assertUnprocessable()->assertJsonPath('code', 'POS_CART_CHANGED');
        Http::assertNothingSent();
        $this->posCheckout()->assertOk();
        DB::table('users')->insert(['user_id' => 8, 'role' => 'admin']); Sanctum::actingAs(User::findOrFail(8));
        $this->posCheckout()->assertForbidden();
        $this->assertDatabaseCount('gateway_payments', 1);
    }

    public function test_migration_round_trip_preserves_existing_cash_sales(): void
    {
        DB::table('pos_transactions')->insert(['cashier_id' => 7, 'total_amount' => 80, 'amount_tendered' => 100, 'change_amount' => 20]);
        $migration = require database_path('migrations/2026_10_18_000001_extend_shared_gateway_payments.php');
        $migration->down();
        $this->assertDatabaseHas('pos_transactions', ['total_amount' => 80, 'change_amount' => 20]);
        $migration->up();
        $this->assertDatabaseHas('pos_transactions', ['total_amount' => 80, 'payment_method' => 'cash', 'gateway_payment_id' => null]);
    }

    public function test_nested_v2_intents_and_missing_mode_cannot_release_reservations(): void
    {
        $this->posCheckout()->assertOk();
        foreach (['succeeded', 'processing', 'awaiting_next_action', 'unknown', 'missing_mode'] as $status) {
            $attributes = ['status' => 'expired', 'livemode' => false, 'payments' => [],
                'payment_intents' => [['id' => 'pi_test', 'type' => 'payment_intent', 'attributes' => ['status' => $status]]]];
            if ($status === 'missing_mode') unset($attributes['livemode']);
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(function () use ($attributes) {
                $this->assertSame(0, DB::transactionLevel(), 'Retrieval must not hold financial locks.');
                return Http::response(['data' => ['id' => 'cs_fixture', 'type' => 'checkout_session', 'attributes' => $attributes]]);
            });
            $this->getJson('/api/pos/online-payment/1')->assertOk()->assertJsonPath('online_payment.status', 'pending');
            $this->deleteJson('/api/pos/online-payment/1')->assertUnprocessable();
            $this->assertDatabaseCount('gateway_stock_reservations', 1);
        }
    }

    public function test_pos_stock_conflict_keeps_the_charge_and_reservations_without_partial_sale(): void
    {
        $this->posCheckout()->assertOk();
        DB::table('inventory_items')->update(['quantity_on_hand' => 0]);
        $event = $this->event(16000);
        $this->webhook($event)->assertOk(); $this->webhook($event)->assertOk();
        $this->assertDatabaseHas('gateway_payments', ['status' => 'paid', 'reconciliation_reason' => 'product_stock_conflict']);
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('gateway_stock_reservations', 1);
    }

    public static function checkoutContexts(): array
    {
        return [['clinic', 50000], ['pos', 16000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutContexts')]
    public function test_qrph_checkout_rejects_other_signed_channels(string $context, int $amount): void
    {
        if ($context === 'clinic') $this->clinicCheckout();
        else $this->posCheckout()->assertOk();
        foreach (['gcash', 'paymaya', 'maya', 'card', 'unknown', null] as $method) {
            $event = $this->event($amount);
            $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['source']['type'] = $method;
            $this->webhook($event)->assertUnprocessable();
        }
        $this->assertDatabaseHas('gateway_payments', ['status' => 'pending', 'provider_payment_id' => null]);
        $this->assertDatabaseHas('clinic_appointments', ['paid' => false]);
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        if ($context === 'pos') $this->assertDatabaseCount('gateway_stock_reservations', 1);
        $this->webhook($this->event($amount))->assertOk();
    }

    public static function historicalChannels(): array
    {
        $cases = [];
        foreach (['clinic' => 50000, 'pos' => 16000] as $context => $amount) {
            foreach (['qrph', 'gcash', 'paymaya', 'maya', 'card'] as $method) $cases[] = [$context, $amount, $method];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historicalChannels')]
    public function test_historical_pending_channels_settle_and_keep_the_online_label(string $context, int $amount, string $method): void
    {
        if ($context === 'clinic') $this->clinicCheckout();
        else $this->posCheckout()->assertOk();
        $attempt = GatewayPayment::firstOrFail();
        $snapshot = $attempt->snapshot;
        unset($snapshot['payment_methods']);
        $attempt->update(['snapshot' => $snapshot]);
        $event = $this->event($amount);
        $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['source']['type'] = $method;
        $this->webhook($event)->assertOk(); $this->webhook($event)->assertOk();
        $this->assertDatabaseHas('gateway_payments', ['status' => 'paid', 'provider_method' => $method]);
        if ($context === 'clinic') {
            $this->getJson('/api/admin/clinic-appointments/1/online-payment')->assertOk()
                ->assertJsonPath('appointment.payment_method_label', 'Online Payment');
        } else {
            $this->getJson('/api/pos/transactions/1')->assertOk()->assertJsonPath('data.payment_method_label', 'Online Payment');
            $this->assertDatabaseCount('inventory_transactions', 1);
        }
        Http::assertSentCount(1);
    }

    public function test_saved_checkout_methods_survive_configuration_changes(): void
    {
        config(['services.paymongo.payment_methods' => ['gcash', 'paymaya', 'card']]);
        $this->posCheckout()->assertOk();
        Http::assertSent(fn ($request) => $request['data']['attributes']['payment_method_types'] === ['gcash', 'paymaya', 'card']);
        $this->assertSame(['gcash', 'paymaya', 'card'], GatewayPayment::firstOrFail()->snapshot['payment_methods']);
        config(['services.paymongo.payment_methods' => ['qrph']]);
        $event = $this->event(16000);
        $event['data']['attributes']['data']['attributes']['payments'][0]['attributes']['source']['type'] = 'gcash';
        $this->webhook($event)->assertOk();
        $this->assertDatabaseHas('gateway_payments', ['status' => 'paid', 'provider_method' => 'gcash']);
    }

    public static function invalidMethods(): array
    {
        return [[[]], [null], ['qrph'], [['unknown']], [['qrph', 'unknown']], [[['qrph']]], [['channel' => 'qrph']], [['qrph', 'qrph']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMethods')]
    public function test_invalid_checkout_configuration_has_no_financial_side_effects(mixed $methods): void
    {
        config(['services.paymongo.payment_methods' => $methods]);
        $this->posCheckout()->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->postJson('/api/admin/clinic-appointments/1/online-payment')->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('gateway_payments', 0);
        $this->assertDatabaseCount('gateway_stock_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_new_checkout_methods_do_not_block_pending_recovery_or_cancellation(): void
    {
        $this->posCheckout()->assertOk();
        config(['services.paymongo.payment_methods' => ['unsupported']]);
        $this->getJson('/api/pos/online-payment/1')->assertOk()->assertJsonPath('online_payment.status', 'pending');
        $this->fakeProvider('expired');
        $this->deleteJson('/api/pos/online-payment/1')->assertOk()->assertJsonPath('online_payment.status', 'cancelled');
        $this->assertDatabaseCount('gateway_stock_reservations', 0);
    }

    private function clinicCheckout(): void
    {
        $this->postJson('/api/admin/clinic-appointments/1/online-payment', [])->assertOk();
    }

    private function posCheckout(string $method = 'online', string $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
    {
        return $this->postJson('/api/pos/online-payment', ['payment_method' => $method, 'request_key' => $key,
            'items' => [['item_id' => 10, 'quantity' => 2]], 'total_amount' => 1]);
    }

    private function fakeProvider(string $status = 'active'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'cs_fixture', 'type' => 'checkout_session',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_fixture', 'status' => $status, 'livemode' => false]]])]);
    }

    private function event(int $amount = 50000, bool $live = false): array
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
