<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Services\GroomingPaymentReadinessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionHistorySearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id');
            $table->string('booking_reference');
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('pet_name');
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
        });
        Schema::create('walkins', function (Blueprint $table) {
            $table->id();
            $table->string('fname');
            $table->string('lname');
        });
        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->decimal('total_amount');
            $table->decimal('cash_received')->nullable();
            $table->string('payment_method')->nullable();
            $table->unsignedInteger('paid_by_user_id')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->text('notes')->nullable();
        });
        Schema::create('clinic_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->string('description');
            $table->decimal('quantity');
            $table->decimal('unit_price');
            $table->decimal('amount');
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id');
            $table->decimal('total_amount');
            $table->decimal('amount_tendered');
            $table->decimal('change_amount');
            $table->string('payment_method');
            $table->string('payment_status');
            $table->dateTime('paid_at');
        });
        Schema::create('grooming_payment_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('payment_id');
            $table->string('item_name')->nullable();
            $table->decimal('subtotal');
        });
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->increments('item_id');
            $table->string('item_name');
            $table->string('unit');
        });
        (require database_path('migrations/2026_07_05_100002_create_pos_transactions_table.php'))->up();
        (require database_path('migrations/2026_07_05_100003_create_pos_transaction_items_table.php'))->up();
        (require database_path('migrations/2026_10_07_100000_add_product_snapshot_to_pos_items.php'))->up();

        DB::table('users')->insert(['user_id' => 1, 'first_name' => 'Gerald', 'last_name' => 'Senining']);
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Hopper']);
        foreach ([1 => '2026-10-03', 2 => '2026-10-02', 3 => '2026-10-03'] as $id => $date) {
            DB::table('bookings')->insert(['booking_id' => $id, 'user_id' => 1, 'booking_reference' => 'BAC-'.$id]);
            DB::table('booking_pets')->insert(['booking_id' => $id, 'pet_id' => 1]);
            DB::table('payments')->insert([
                'payment_id' => $id, 'booking_id' => $id, 'total_amount' => 850,
                'amount_tendered' => 1000, 'change_amount' => 150, 'payment_method' => 'cash',
                'payment_status' => $id === 3 ? 'pending' : 'paid', 'paid_at' => $date.' 12:00:00',
            ]);
        }
        $this->mock(GroomingPaymentReadinessService::class)
            ->shouldReceive('summarize')->andReturn(['pets' => []]);
        // Prove the query works even when LIKE itself is case sensitive.
        DB::statement('PRAGMA case_sensitive_like = ON');
    }

    public function test_owner_pet_and_reference_search_ignore_case_and_preserve_filters(): void
    {
        foreach (['gErAlD', 'SENINING', 'GeRaLd SeNiNiNg', 'hOpPeR', 'bAc-1'] as $search) {
            $data = $this->search(['search' => $search, 'period' => 'day', 'date' => '2026-10-03']);
            $this->assertSame(1, $data['total'], $search);
            $this->assertSame('BAC-1', $data['transactions'][0]['reference']);
            $this->assertSame(850, $data['transactions'][0]['finalPrice']);
            $this->assertSame(1000, $data['transactions'][0]['amountPaid']);
            $this->assertSame(150, $data['transactions'][0]['changeGiven']);
        }
        $this->assertSame(0, $this->search(['search' => 'BAC-3'])['total'], 'Pending payments stay out of history');
        $this->assertSame(0, $this->search(['search' => 'missing'])['total']);
        $this->assertSame(0, $this->search(['search' => 'BAC-2', 'date' => '2026-10-03'])['total']);
        $this->assertSame(2, $this->search(['search' => 'HOPPER', 'period' => 'month', 'month' => '2026-10'])['total']);
        $this->assertSame(2, $this->search(['search' => 'HOPPER', 'period' => 'year', 'year' => '2026'])['total']);
        $this->assertSame(2, $this->search([])['total'], 'Unfiltered history is preserved');
    }

    public function test_unified_history_filters_totals_search_and_pos_snapshots(): void
    {
        DB::table('inventory_items')->insert(['item_id' => 1, 'item_name' => 'Current Name', 'unit' => 'bottle']);
        DB::table('pos_transactions')->insert([
            'pos_id' => 1, 'cashier_id' => 1, 'total_amount' => 500, 'amount_tendered' => 1000,
            'change_amount' => 500, 'created_at' => '2026-10-03 13:00:00',
        ]);
        DB::table('pos_transaction_items')->insert([
            'pos_id' => 1, 'item_id' => 1, 'item_name' => 'Recorded Shampoo', 'unit' => 'bottle',
            'quantity' => 2, 'price_at_sale' => 250, 'subtotal' => 500,
        ]);
        $all = $this->search([]);
        $this->assertSame(3, $all['total']);
        $this->assertEquals(2200, $all['collection_total']);
        $this->assertEquals(1350, $all['date_totals']['2026-10-03']);
        $this->assertSame(['pos-1', 'grooming-1', 'grooming-2'], array_column($all['transactions'], 'key'));
        $grooming = $this->search(['transaction_type' => 'grooming_payment']);
        $this->assertSame(2, $grooming['total']);
        $this->assertEquals(1700, $grooming['collection_total']);
        $sales = $this->search(['transaction_type' => 'product_sale']);
        $this->assertSame(1, $sales['total']);
        $this->assertEquals(500, $sales['collection_total']);
        $sale = $sales['transactions'][0];
        $this->assertSame('Product Sale', $sale['transactionTypeLabel']);
        $this->assertSame('POS-000001', $sale['reference']);
        $this->assertSame('Gerald Senining', $sale['processedByName']);
        $this->assertSame('Recorded Shampoo', $sale['items'][0]['item_name']);
        $this->assertSame('250.00', $sale['items'][0]['price_at_sale']);
        $this->assertArrayNotHasKey('ownerName', $sale);
        $this->assertArrayNotHasKey('petName', $sale);
        $this->assertEquals(1000, $sale['amountPaid']);
        $this->assertEquals(500, $sale['changeGiven']);
        foreach (['pOs-000001', 'rEcOrDeD sHaMpOo', 'Shampoo'] as $term) {
            $this->assertSame(1, $this->search(['search' => $term])['total']);
        }
        $this->assertSame(0, $this->search(['search' => 'Current Name'])['total'], 'Search uses the recorded product snapshot');
        foreach ([['period' => 'day', 'date' => '2026-10-03'], ['period' => 'month', 'month' => '2026-10'], ['period' => 'week', 'week' => '2026-W40'], ['period' => 'year', 'year' => '2026']] as $filter) {
            $this->assertSame(1, $this->search(['transaction_type' => 'product_sale', ...$filter])['total']);
        }
        $this->assertSame(0, $this->search(['transaction_type' => 'product_sale', 'date' => '2026-10-04'])['total']);
    }

    public function test_collection_and_daily_totals_cover_the_full_filtered_history_across_pages(): void
    {
        foreach (range(1, 55) as $id) {
            DB::table('pos_transactions')->insert([
                'pos_id' => $id, 'cashier_id' => 1, 'total_amount' => 10, 'amount_tendered' => 20,
                'change_amount' => 10, 'created_at' => '2026-10-03 13:00:00',
            ]);
        }
        $first = $this->search(['transaction_type' => 'product_sale']);
        $second = $this->search(['transaction_type' => 'product_sale', 'page' => 2]);
        $this->assertCount(50, $first['transactions']);
        $this->assertCount(5, $second['transactions']);
        foreach ([$first, $second] as $page) {
            $this->assertSame(55, $page['total']);
            $this->assertEquals(550, $page['collection_total']);
            $this->assertEquals(550, $page['date_totals']['2026-10-03']);
            $this->assertSame(2, $page['last_page']);
        }
        $this->assertEmpty(array_intersect(array_column($first['transactions'], 'key'), array_column($second['transactions'], 'key')));
    }

    public function test_snapshot_migration_backfills_existing_names_without_changing_recorded_prices(): void
    {
        Schema::table('pos_transaction_items', fn (Blueprint $table) => $table->dropColumn(['item_name', 'unit']));
        DB::table('inventory_items')->insert(['item_id' => 1, 'item_name' => 'Legacy Shampoo', 'unit' => 'bottle']);
        DB::table('pos_transactions')->insert(['pos_id' => 1, 'cashier_id' => 1, 'total_amount' => 100,
            'amount_tendered' => 200, 'change_amount' => 100, 'created_at' => '2026-10-03 13:00:00']);
        DB::table('pos_transaction_items')->insert(['pos_id' => 1, 'item_id' => 1, 'quantity' => 2,
            'price_at_sale' => 50, 'subtotal' => 100]);
        (require database_path('migrations/2026_10_07_100000_add_product_snapshot_to_pos_items.php'))->up();
        DB::table('inventory_items')->where('item_id', 1)->update(['item_name' => 'Renamed Shampoo']);
        $line = $this->search(['transaction_type' => 'product_sale'])['transactions'][0]['items'][0];
        $this->assertSame('Legacy Shampoo', $line['item_name']);
        $this->assertSame('bottle', $line['unit']);
        $this->assertSame('50.00', $line['price_at_sale']);
        $this->assertSame('100.00', $line['subtotal']);
    }

    public function test_clinic_payments_use_recorded_charges_and_remain_distinct_from_grooming(): void
    {
        DB::table('clinic_appointments')->insert([
            'id' => 1, 'appointment_reference' => 'CL-20261003-001', 'user_id' => 1, 'pet_id' => 1,
            'status' => 'completed', 'paid' => true, 'total_amount' => 725.50,
            'cash_received' => 1000, 'payment_method' => 'cash', 'paid_by_user_id' => 1,
            'paid_at' => '2026-10-03 14:00:00', 'chief_complaint' => 'Private encounter', 'notes' => 'Private notes',
        ]);
        DB::table('clinic_charges')->insert([
            ['clinic_appointment_id' => 1, 'description' => 'Consultation', 'quantity' => 1, 'unit_price' => 500, 'amount' => 500],
            ['clinic_appointment_id' => 1, 'description' => 'Clinic supplies', 'quantity' => 2, 'unit_price' => 112.75, 'amount' => 225.50],
        ]);
        foreach (['for_payment', 'cancelled', 'completed'] as $index => $status) {
            DB::table('clinic_appointments')->insert([
                'appointment_reference' => 'CL-EXCLUDED-'.$index, 'status' => $status,
                'paid' => $status !== 'completed', 'total_amount' => 9000, 'paid_at' => '2026-10-03 14:00:00',
            ]);
        }
        $all = $this->search([]);
        $this->assertSame(3, $all['total']);
        $this->assertEquals(2425.50, $all['collection_total']);
        $this->assertEquals(1575.50, $all['date_totals']['2026-10-03']);
        $this->assertSame('clinic-1', $all['transactions'][0]['key']);
        $clinic = $this->search(['transaction_type' => 'clinic_payment']);
        $this->assertSame(1, $clinic['total']);
        $this->assertEquals(725.50, $clinic['collection_total']);
        $tx = $clinic['transactions'][0];
        $this->assertSame('Clinic Payment', $tx['transactionTypeLabel']);
        $this->assertSame('Gerald Senining', $tx['ownerName']);
        $this->assertSame('Hopper', $tx['petName']);
        $this->assertSame('Gerald Senining', $tx['processedByName']);
        $this->assertEquals(1000, $tx['amountPaid']);
        $this->assertEquals(274.50, $tx['changeGiven']);
        $this->assertSame(['Consultation', 'Clinic supplies'], array_column($tx['charges'], 'description'));
        $this->assertEquals([500, 225.50], array_column($tx['charges'], 'amount'));
        $this->assertEquals(2, $tx['charges'][1]['quantity']);
        foreach (['chief_complaint', 'notes', 'record', 'paymentSummary', 'groomingServicesTotal'] as $field) {
            $this->assertArrayNotHasKey($field, $tx);
        }
        foreach (['cL-20261003-001', 'gErAlD sEnInInG', 'hOpPeR'] as $search) {
            $this->assertSame(1, $this->search(['transaction_type' => 'clinic_payment', 'search' => $search])['total']);
        }
        foreach ([['period' => 'day', 'date' => '2026-10-03'], ['period' => 'month', 'month' => '2026-10'], ['period' => 'week', 'week' => '2026-W40'], ['period' => 'year', 'year' => '2026']] as $filter) {
            $this->assertSame(1, $this->search(['transaction_type' => 'clinic_payment', ...$filter])['total']);
        }
        $this->assertSame(0, $this->search(['transaction_type' => 'clinic_payment', 'date' => '2026-10-04'])['total']);
        $this->assertSame(2, $this->search(['transaction_type' => 'grooming_payment'])['total']);
        $this->assertSame(0, $this->search(['transaction_type' => 'product_sale'])['total']);
    }

    public function test_clinic_walkin_and_unregistered_owner_search_and_missing_payment_metadata(): void
    {
        DB::table('walkins')->insert(['id' => 1, 'fname' => 'Erwin', 'lname' => 'Francisco']);
        DB::table('unregistered_customers')->insert(['id' => 1, 'first_name' => 'Maria', 'last_name' => 'Santos']);
        DB::table('pets')->insert(['pet_id' => 2, 'pet_name' => 'Mark', 'unregistered_customer_id' => 1]);
        foreach ([1, 2] as $id) {
            DB::table('clinic_appointments')->insert([
                'id' => $id, 'appointment_reference' => 'CL-LEGACY-'.$id, 'walkin_id' => $id === 1 ? 1 : null,
                'pet_id' => 2, 'status' => 'completed', 'paid' => true, 'total_amount' => 500,
            ]);
        }
        foreach (['eRwIn FrAnCiScO' => 'Erwin Francisco', 'mArIa SaNtOs' => 'Maria Santos'] as $search => $name) {
            $txs = $this->search(['transaction_type' => 'clinic_payment', 'search' => $search])['transactions'];
            $this->assertContains($name, array_column($txs, 'ownerName'));
        }
        $data = $this->search(['transaction_type' => 'clinic_payment']);
        $this->assertEquals(1000, $data['date_totals']['unknown']);
        $this->assertNull($data['transactions'][0]['paidAt']);
        $this->assertNull($data['transactions'][0]['amountPaid']);
        $this->assertNull($data['transactions'][0]['changeGiven']);
        $this->assertEmpty($data['transactions'][0]['charges']);
    }

    public function test_clinic_totals_and_pagination_cover_all_matching_payments(): void
    {
        foreach (range(1, 55) as $id) {
            DB::table('clinic_appointments')->insert([
                'id' => $id, 'appointment_reference' => 'CL-'.$id, 'status' => 'completed',
                'paid' => true, 'total_amount' => 100, 'payment_method' => 'gcash',
                'paid_at' => '2026-10-03 14:00:00',
            ]);
        }
        $first = $this->search(['transaction_type' => 'clinic_payment']);
        $second = $this->search(['transaction_type' => 'clinic_payment', 'page' => 2]);
        $this->assertCount(50, $first['transactions']);
        $this->assertCount(5, $second['transactions']);
        foreach ([$first, $second] as $page) {
            $this->assertSame(55, $page['total']);
            $this->assertEquals(5500, $page['collection_total']);
            $this->assertEquals(5500, $page['date_totals']['2026-10-03']);
            $this->assertSame('GCash', $page['transactions'][0]['paymentMethodLabel']);
            $this->assertNull($page['transactions'][0]['amountPaid']);
            $this->assertNull($page['transactions'][0]['changeGiven']);
        }
        $this->assertEmpty(array_intersect(array_column($first['transactions'], 'key'), array_column($second['transactions'], 'key')));
    }

    private function search(array $query): array
    {
        return (new PaymentController)->index(Request::create('/api/admin/transactions', 'GET', $query))->getData(true);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historicalVerificationSources')]
    public function test_qr_history_includes_verification_and_searchable_reference_without_changing_totals(string $source): void
    {
        (require database_path('migrations/2026_10_23_000001_create_qr_payments.php'))->up();
        $qr = \App\Models\QrPayment::create([
            'payable_type' => \App\Models\Booking::class, 'payable_id' => 1,
            'external_reference' => 'BANK123456', 'received_amount' => 850, 'transaction_at' => '2026-10-03 11:59:00',
            'verification_source' => $source, 'verified_by' => 7, 'verified_by_name' => 'Staff Verifier',
            'verified_at' => '2026-10-03 12:00:00', 'settings_version' => 1,
            'merchant_name' => 'Bethlehem', 'provider_name' => 'Bank', 'qr_image_hash' => hash('sha256', 'fixture'),
        ]);
        DB::table('payments')->where('payment_id', 1)->update(['payment_method' => 'qrph', 'qr_payment_id' => $qr->id, 'amount_tendered' => 850, 'change_amount' => 0]);
        $all = $this->search([]);
        $this->assertSame(2, $all['total']);
        $this->assertEquals(1700, $all['collection_total']);
        $data = $this->search(['search' => 'bAnK123456']);
        $this->assertSame(1, $data['total']);
        $transaction = $data['transactions'][0];
        $this->assertSame('QR Payment', $transaction['paymentMethodLabel']);
        $this->assertSame('Staff Verifier', $transaction['qrPayment']['verified_by_name']);
        $this->assertSame($source, $transaction['qrPayment']['verification_source']);
        $this->assertSame($source, $qr->fresh()->verification_source);
        $this->assertSame('BANK123456', $transaction['qrPayment']['external_reference']);
        $this->assertSame(1, $transaction['qrPayment']['settings_version']);
        $this->assertArrayNotHasKey('image_path', $transaction['qrPayment']);
        $this->assertArrayNotHasKey('proof_url', $transaction['qrPayment']);
        $this->assertEquals(850, $data['collection_total']);
    }

    public static function historicalVerificationSources(): array
    {
        return [['receiving_history'], ['merchant_notification']];
    }
}
