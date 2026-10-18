<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroomingPerformanceReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        if (! ($driver === 'sqlite' && $database === ':memory:')
            && ! (in_array($driver, ['mysql', 'mariadb'], true) && preg_match('/^bethlehem_reports_test_[a-f0-9]{12}$/', $database))) {
            throw new \RuntimeException('Reports fixtures require in-memory SQLite or the isolated grooming-reports-mysql.php runner.');
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->assertStringContainsString('ONLY_FULL_GROUP_BY', DB::selectOne('SELECT @@SESSION.sql_mode AS modes')->modes);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 12:00:00', 'Asia/Manila'));
        Schema::create('bookings', function (Blueprint $t) {
            $t->increments('booking_id');
            $t->integer('user_id')->nullable();
            $t->integer('walkin_id')->nullable();
            $t->string('status')->default('completed');
            $t->date('booking_date')->default('2026-10-01');
            $t->dateTime('grooming_finished_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
        });
        Schema::create('booking_pets', function (Blueprint $t) {
            $t->increments('booking_pet_id');
            $t->integer('booking_id');
            $t->string('grooming_state')->nullable();
            $t->dateTime('grooming_end_time')->nullable();
            $t->dateTime('cancelled_at')->nullable();
        });
        Schema::create('walkins', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->integer('unregistered_customer_id')->nullable();
            $t->string('phone')->nullable();
        });
        Schema::create('services', function (Blueprint $t) {
            $t->increments('service_id');
            $t->string('service_name');
            $t->string('slug')->nullable();
            $t->string('kind')->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('addons', function (Blueprint $t) {
            $t->increments('addon_id');
            $t->string('addon_name');
        });
        Schema::create('booking_services', function (Blueprint $t) {
            $t->increments('booking_service_id');
            $t->integer('booking_id');
            $t->integer('booking_pet_id');
            $t->integer('service_id')->nullable();
            $t->integer('addon_id')->nullable();
            $t->string('service_name_snapshot')->nullable();
            $t->string('service_kind_snapshot')->nullable();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->increments('payment_id');
            $t->integer('booking_id');
            $t->decimal('total_amount', 10, 2);
            $t->string('payment_status')->default('paid');
            $t->string('payment_method')->default('cash');
            $t->string('payment_provider')->nullable();
            $t->integer('gateway_payment_id')->nullable();
            $t->dateTime('paid_at')->nullable();
        });
        Schema::create('gateway_payments', function (Blueprint $t) {
            $t->increments('id');
            $t->boolean('livemode')->default(true);
            $t->string('status')->default('paid');
            $t->string('context')->default('grooming');
            $t->integer('payable_id')->nullable();
            $t->json('snapshot')->nullable();
        });
        Schema::create('grooming_payment_products', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('payment_id');
            $t->decimal('subtotal', 10, 2);
        });
        Sanctum::actingAs((new User)->forceFill(['user_id' => 1, 'role' => 'admin']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        if (isset($this->app) && $this->app && preg_match('/^bethlehem_reports_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            foreach (['grooming_payment_products', 'gateway_payments', 'payments', 'booking_services',
                'addons', 'services', 'walkins', 'booking_pets', 'bookings', 'migrations'] as $table) {
                Schema::dropIfExists($table);
            }
        }
        parent::tearDown();
    }

    private function booking(int $owner, array $attributes = []): int
    {
        return DB::table('bookings')->insertGetId($attributes + ['user_id' => $owner], 'booking_id');
    }

    private function pet(int $booking, string $at, string $state = 'finished'): int
    {
        return DB::table('booking_pets')->insertGetId(['booking_id' => $booking,
            'grooming_state' => $state, 'grooming_end_time' => $at], 'booking_pet_id');
    }

    private function payment(int $booking, float $amount, string $at, array $attributes = []): int
    {
        return DB::table('payments')->insertGetId($attributes + ['booking_id' => $booking,
            'total_amount' => $amount, 'paid_at' => $at], 'payment_id');
    }

    private function report(string $query = '')
    {
        return $this->getJson('/api/admin/reports/grooming'.$query);
    }

    public function test_full_period_collections_exclude_products_duplicates_test_refunds_and_other_contexts(): void
    {
        // More than the transaction endpoint's page size, with two product lines per sale.
        for ($i = 0; $i < 31; $i++) {
            $booking = $this->booking($i + 1);
            $payment = $this->payment($booking, 700, '2026-10-05 10:00:00');
            DB::table('grooming_payment_products')->insert([
                ['payment_id' => $payment, 'subtotal' => 150], ['payment_id' => $payment, 'subtotal' => 50],
            ]);
            $this->payment($booking, 700, '2026-10-05 10:00:00');
        }
        foreach ([['livemode' => false], ['status' => 'refunded'], ['context' => 'clinic'], ['context' => 'pos']] as $attributes) {
            $booking = $this->booking(80);
            $gateway = DB::table('gateway_payments')->insertGetId($attributes + ['payable_id' => $booking]);
            $this->payment($booking, 900, '2026-10-05 10:00:00', ['gateway_payment_id' => $gateway, 'payment_provider' => 'paymongo']);
        }
        $this->payment($this->booking(90), 900, '2026-10-05 10:00:00', ['payment_status' => 'pending']);
        $this->payment($this->booking(91), 900, '2026-10-05 10:00:00', ['payment_status' => 'refunded']);
        $this->payment($this->booking(92, ['status' => 'cancelled']), 900, '2026-10-05 10:00:00');
        $this->payment($this->booking(93), 900, '2026-10-05 10:00:00', ['payment_provider' => 'paymongo']);
        // A valid legacy payment method and a live checkout with unrecorded product stock conflict.
        $this->payment($this->booking(94), 250, '2026-10-06 10:00:00', ['payment_method' => 'gcash']);
        $booking = $this->booking(95);
        $gateway = DB::table('gateway_payments')->insertGetId(['payable_id' => $booking,
            'snapshot' => json_encode(['summary' => ['final_booking_total' => '600.00']])]);
        $this->payment($booking, 1000, '2026-10-06 10:00:00', ['gateway_payment_id' => $gateway, 'payment_provider' => 'paymongo']);
        $response = $this->report()->assertOk()->assertJsonPath('overview.collections', 16350)
            ->assertJsonPath('overview.paidBookings', 33)->assertJsonPath('overview.averageSale', 495.45);
        $this->assertEquals(16350, array_sum(array_column($response->json('performance'), 'collections')));
    }

    public function test_pet_completion_and_historical_service_counts_do_not_multiply_or_include_cancelled_pets(): void
    {
        $booking = $this->booking(5, ['status' => 'in_progress', 'grooming_finished_at' => null]);
        $first = $this->pet($booking, '2026-10-04 09:00:00');
        $second = $this->pet($booking, '2026-10-05 09:00:00');
        $cancelled = $this->pet($booking, '2026-10-05 09:00:00', 'cancelled');
        $unfinished = $this->pet($booking, '2026-10-05 09:00:00', 'not_started');
        DB::table('services')->insert(['service_id' => 1, 'service_name' => 'Renamed', 'kind' => 'package', 'is_active' => false]);
        DB::table('addons')->insert(['addon_id' => 1, 'addon_name' => 'Nail Trim']);
        foreach ([$first, $second, $cancelled, $unfinished] as $pet) {
            DB::table('booking_services')->insert(['booking_id' => $booking, 'booking_pet_id' => $pet,
                'service_id' => 1, 'service_name_snapshot' => 'Original Package', 'service_kind_snapshot' => 'package']);
            DB::table('booking_services')->insert(['booking_id' => $booking, 'booking_pet_id' => $pet, 'addon_id' => 1]);
        }
        // Deleted catalogue entry still reports its snapshot.
        DB::table('booking_services')->insert(['booking_id' => $booking, 'booking_pet_id' => $first,
            'service_name_snapshot' => 'Retired Bath', 'service_kind_snapshot' => 'ala_carte']);
        $response = $this->report()->assertOk()->assertJsonPath('overview.completedVisits', 2);
        $services = collect($response->json('services'))->keyBy('name');
        $this->assertEquals(2, $services['Original Package']['count']);
        $this->assertEquals(2, $services['Nail Trim']['count']);
        $this->assertEquals('addon', $services['Nail Trim']['kind']);
        $this->assertEquals(1, $services['Retired Bath']['count']);
        $this->assertEquals(2, array_sum(array_column($response->json('performance'), 'visits')));
    }

    public function test_custom_dates_use_completion_and_payment_dates_with_inclusive_manila_boundaries(): void
    {
        $booking = $this->booking(5, ['booking_date' => '2026-08-01']);
        $this->pet($booking, '2026-09-30 23:59:59');
        $this->pet($booking, '2026-10-01 00:00:00');
        $this->pet($booking, '2026-10-31 23:59:59');
        $this->pet($booking, '2026-11-01 00:00:00');
        foreach (['2026-09-30 23:59:59', '2026-10-01 00:00:00', '2026-10-31 23:59:59', '2026-11-01 00:00:00'] as $at) {
            $this->payment($this->booking(9), 100, $at);
        }
        $this->report('?period=custom&start=2026-10-01&end=2026-10-31')->assertOk()
            ->assertJsonPath('overview.completedVisits', 2)->assertJsonPath('overview.collections', 200)
            ->assertJsonPath('period.timezone', 'Asia/Manila');
        $this->report('?period=today')->assertOk()->assertJsonPath('period.start', '2026-10-18')->assertJsonPath('period.end', '2026-10-18');
        $this->report('?period=last_7_days')->assertOk()->assertJsonPath('period.start', '2026-10-12');
        $this->report('?period=last_month')->assertOk()->assertJsonPath('period.start', '2026-09-01')->assertJsonPath('period.end', '2026-09-30');
    }

    public function test_customers_use_distinct_booking_history_and_linked_walkin_identity(): void
    {
        $this->pet($this->booking(5), '2026-09-12 09:00:00');
        $this->pet($this->booking(5), '2026-10-12 09:00:00');
        $new = $this->booking(6);
        $this->pet($new, '2026-10-12 09:00:00');
        $this->pet($new, '2026-10-12 09:00:00');
        $this->pet($this->booking(7), '2026-10-12 09:00:00');
        $this->pet($this->booking(7), '2026-10-13 09:00:00');
        foreach ([1, 2] as $id) DB::table('walkins')->insert(['id' => $id, 'unregistered_customer_id' => 4]);
        $this->pet($this->booking(0, ['user_id' => null, 'walkin_id' => 1]), '2026-09-12 09:00:00');
        $this->pet($this->booking(0, ['user_id' => null, 'walkin_id' => 2]), '2026-10-12 09:00:00');
        $this->booking(8, ['status' => 'cancelled', 'cancelled_at' => '2026-10-02 09:00:00']);
        $this->booking(9, ['status' => 'no_show', 'booking_date' => '2026-10-03']);
        $this->report()->assertOk()->assertJsonPath('overview.returningCustomers', 3)
            ->assertJsonPath('customers.new', 1)->assertJsonPath('customers.returning', 3)
            ->assertJsonPath('outcomes.completed', 5)->assertJsonPath('outcomes.cancelled', 1)->assertJsonPath('outcomes.noShow', 1);
    }

    public function test_empty_periods_validation_grouping_and_authorization(): void
    {
        $this->report()->assertOk()->assertJsonPath('overview.collections', 0)->assertJsonPath('overview.completedVisits', 0)
            ->assertJsonPath('services', [])->assertJsonPath('period.grouping', 'day');
        foreach (['?period=wrong', '?period=custom', '?period=custom&start=2026-02-30&end=2026-03-01',
            '?period=custom&start=2026-10-31&end=2026-10-01'] as $query) $this->report($query)->assertUnprocessable();
        $this->report('?period=custom&start=2026-01-01&end=2026-03-31')->assertOk()->assertJsonPath('period.grouping', 'week');
        $this->report('?period=custom&start=2025-01-01&end=2026-10-01')->assertOk()->assertJsonPath('period.grouping', 'month');
        foreach (['staff', 'customer'] as $role) {
            Sanctum::actingAs((new User)->forceFill(['user_id' => 2, 'role' => $role]));
            $this->report()->assertForbidden();
        }
    }

    public function test_refresh_reflects_new_completion_and_paid_records(): void
    {
        $booking = $this->booking(5);
        $this->report()->assertOk()->assertJsonPath('overview.completedVisits', 0);
        $this->pet($booking, '2026-10-15 10:00:00');
        $this->payment($booking, 600, '2026-10-16 10:00:00');
        $this->report()->assertOk()->assertJsonPath('overview.completedVisits', 1)->assertJsonPath('overview.collections', 600);
    }

    public function test_snapshot_migration_preserves_existing_names_when_catalogue_changes(): void
    {
        Schema::table('booking_services', fn (Blueprint $t) => $t->dropColumn(['service_name_snapshot', 'service_kind_snapshot']));
        DB::table('services')->insert(['service_id' => 1, 'service_name' => 'Legacy Package', 'kind' => 'package']);
        DB::table('addons')->insert(['addon_id' => 2, 'addon_name' => 'Legacy Add-on']);
        $booking = $this->booking(5);
        $pet = $this->pet($booking, '2026-10-15 10:00:00');
        DB::table('booking_services')->insert([
            ['booking_id' => $booking, 'booking_pet_id' => $pet, 'service_id' => 1, 'addon_id' => null],
            ['booking_id' => $booking, 'booking_pet_id' => $pet, 'service_id' => null, 'addon_id' => 2],
        ]);
        (require database_path('migrations/2026_10_18_000002_snapshot_grooming_report_services.php'))->up();
        DB::table('services')->where('service_id', 1)->update(['service_name' => 'Changed', 'is_active' => false]);
        DB::table('addons')->where('addon_id', 2)->update(['addon_name' => 'Changed Add-on']);
        $this->report()->assertOk()->assertJsonFragment(['name' => 'Legacy Package', 'kind' => 'package', 'count' => 1])
            ->assertJsonFragment(['name' => 'Legacy Add-on', 'kind' => 'addon', 'count' => 1]);
    }

    public function test_early_online_payment_stock_conflicts_keep_frozen_products_out_of_historical_collections(): void
    {
        Schema::table('booking_services', fn (Blueprint $t) => $t->dropColumn(['service_name_snapshot', 'service_kind_snapshot']));
        $booking = $this->booking(5);
        $gateway = DB::table('gateway_payments')->insertGetId(['payable_id' => $booking, 'snapshot' => json_encode([
            'total' => '850.00', 'summary' => ['final_booking_total' => null],
            'products' => [['subtotalCents' => 25000]],
        ])]);
        $this->payment($booking, 850, '2026-10-12 10:00:00', ['payment_provider' => 'paymongo', 'gateway_payment_id' => $gateway]);
        (require database_path('migrations/2026_10_18_000002_snapshot_grooming_report_services.php'))->up();
        $this->report()->assertOk()->assertJsonPath('overview.collections', 600)->assertJsonPath('overview.averageSale', 600);
    }

    public function test_fresh_install_payment_key_and_legacy_pet_dates_remain_reportable(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->renameColumn('payment_id', 'id'));
        $booking = $this->booking(5, ['grooming_finished_at' => '2026-10-08 14:00:00']);
        DB::table('booking_pets')->insert([
            ['booking_id' => $booking, 'grooming_state' => 'finished', 'grooming_end_time' => null],
            ['booking_id' => $booking, 'grooming_state' => null, 'grooming_end_time' => '2026-10-07 14:00:00'],
        ]);
        $payment = DB::table('payments')->insertGetId(['booking_id' => $booking, 'total_amount' => 700, 'paid_at' => '2026-10-09 09:00:00']);
        DB::table('grooming_payment_products')->insert(['payment_id' => $payment, 'subtotal' => 100]);
        $this->report()->assertOk()->assertJsonPath('overview.collections', 600)->assertJsonPath('overview.completedVisits', 2);
        $weekly = $this->report('?period=custom&start=2026-09-01&end=2026-11-01')->assertOk();
        $this->assertEquals(600, array_sum(array_column($weekly->json('performance'), 'collections')));
        $this->assertEquals(2, array_sum(array_column($weekly->json('performance'), 'visits')));
        $monthly = $this->report('?period=custom&start=2026-01-01&end=2026-12-31')->assertOk();
        $this->assertEquals(600, array_sum(array_column($monthly->json('performance'), 'collections')));
        $this->assertEquals(2, array_sum(array_column($monthly->json('performance'), 'visits')));
    }

    public function test_unauthenticated_requests_cannot_read_reports(): void
    {
        $this->app['auth']->forgetGuards();
        $this->report()->assertUnauthorized();
    }

    public function test_database_failures_return_a_concise_error_without_sql_or_zero_metrics(): void
    {
        $failure = new \Illuminate\Database\QueryException('mysql', 'SELECT private_column FROM private_table', [],
            new \PDOException("SQLSTATE[42000]: 1055 private_column isn't in GROUP BY"));
        $this->mock(\App\Services\GroomingPerformanceReport::class)->shouldReceive('build')->once()->andThrow($failure);

        $this->report()->assertStatus(500)->assertExactJson([
            'success' => false, 'message' => 'Unable to load reports. Please try again.',
        ]);
    }

    public function test_snapshot_migration_runs_once_without_changing_booking_or_payment_amounts(): void
    {
        Schema::table('booking_services', fn (Blueprint $t) => $t->dropColumn(['service_name_snapshot', 'service_kind_snapshot']));
        DB::table('services')->insert(['service_id' => 1, 'service_name' => 'Historical Package', 'kind' => 'package']);
        $booking = $this->booking(5);
        $pet = $this->pet($booking, '2026-10-12 10:00:00');
        DB::table('booking_services')->insert(['booking_id' => $booking, 'booking_pet_id' => $pet, 'service_id' => 1]);
        $gateway = DB::table('gateway_payments')->insertGetId(['payable_id' => $booking, 'snapshot' => json_encode([
            'total' => '850.00', 'products' => [['subtotalCents' => 25000]],
        ])]);
        $this->payment($booking, 850, '2026-10-12 10:00:00', ['gateway_payment_id' => $gateway, 'payment_provider' => 'paymongo']);
        $before = [DB::table('bookings')->get()->toArray(), DB::table('payments')->get()->toArray()];
        $arguments = ['--path' => 'database/migrations/2026_10_18_000002_snapshot_grooming_report_services.php', '--force' => true];
        $this->artisan('migrate', $arguments)->assertExitCode(0);
        $snapshot = DB::table('gateway_payments')->value('snapshot');
        $this->artisan('migrate', $arguments)->assertExitCode(0);
        $this->assertSame($snapshot, DB::table('gateway_payments')->value('snapshot'));
        $this->assertEquals($before, [DB::table('bookings')->get()->toArray(), DB::table('payments')->get()->toArray()]);
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_18_000002_snapshot_grooming_report_services')->count());
        $this->report()->assertOk()->assertJsonPath('overview.collections', 600)
            ->assertJsonFragment(['name' => 'Historical Package', 'kind' => 'package', 'count' => 1]);
    }
}
