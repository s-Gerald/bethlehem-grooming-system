<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicPerformanceReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (getenv('REPORTS_REQUIRE_MYSQL')) $this->assertSame('mysql', DB::getDriverName());
        if (! (DB::getDriverName() === 'sqlite' && $database === ':memory:')
            && ! (DB::getDriverName() === 'mysql' && preg_match('/^bethlehem_reports_test_[a-f0-9]{12}$/', $database))) {
            throw new \RuntimeException('Clinic report fixtures require an isolated test database.');
        }
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('ONLY_FULL_GROUP_BY', DB::selectOne('SELECT @@SESSION.sql_mode AS modes')->modes);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 12:00:00', 'Asia/Manila'));
        Schema::create('clinic_appointments', function (Blueprint $t) {
            $t->increments('id');
            $t->string('status')->default('completed');
            $t->boolean('paid')->default(true);
            $t->decimal('total_amount', 10, 2)->nullable()->default(500);
            $t->string('payment_method')->nullable()->default('cash');
            $t->integer('gateway_payment_id')->nullable();
            $t->dateTime('paid_at')->nullable()->default('2026-10-10 10:00:00');
            $t->dateTime('consultation_finished_at')->nullable()->default('2026-10-09 10:00:00');
            $t->date('appointment_date')->default('2026-08-01');
            $t->dateTime('updated_at')->nullable()->default('2026-10-11 10:00:00');
            $t->dateTime('archived_at')->nullable();
        });
        Schema::create('gateway_payments', function (Blueprint $t) {
            $t->increments('id');
            $t->string('provider')->default('paymongo');
            $t->string('context')->default('clinic');
            $t->string('payable_type');
            $t->integer('payable_id');
            $t->boolean('livemode')->default(true);
            $t->string('status')->default('paid');
            $t->string('currency')->default('PHP');
            $t->decimal('amount', 10, 2)->default(750);
            $t->dateTime('paid_at')->nullable()->default('2026-10-10 11:00:00');
            $t->string('reconciliation_reason')->nullable();
        });
        Schema::create('clinic_services', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
        });
        Schema::create('clinic_case_services', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('clinic_appointment_id');
            $t->integer('clinic_service_id')->nullable();
            $t->string('status')->default('performed');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->json('service_snapshot')->nullable();
            $t->dateTime('performed_at')->nullable()->default('2026-10-08 09:00:00');
            $t->dateTime('cancelled_at')->nullable();
        });
        Schema::create('clinic_charges', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('clinic_appointment_id');
            $t->integer('clinic_case_service_id')->nullable();
            $t->string('kind');
            $t->string('description');
        });
        Sanctum::actingAs((new User)->forceFill(['user_id' => 1, 'role' => 'admin']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        if ($this->app && preg_match('/^bethlehem_reports_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            foreach (['clinic_charges', 'clinic_case_services', 'clinic_services', 'gateway_payments', 'clinic_appointments'] as $table) {
                Schema::dropIfExists($table);
            }
        }
        parent::tearDown();
    }

    private function clinicCase(array $attributes = []): int
    {
        return DB::table('clinic_appointments')->insertGetId($attributes + ['status' => 'completed']);
    }

    private function online(array $gateway = [], array $attributes = []): int
    {
        $id = $this->clinicCase($attributes + ['payment_method' => 'gcash']);
        $payment = DB::table('gateway_payments')->insertGetId($gateway + ['payable_id' => $id,
            'payable_type' => (new ClinicAppointment)->getMorphClass()]);
        DB::table('clinic_appointments')->where('id', $id)->update(['gateway_payment_id' => $payment]);
        return $id;
    }

    private function report(string $query = '')
    {
        return $this->getJson('/api/admin/reports/clinic'.$query);
    }

    public function test_collections_use_only_completed_paid_cash_and_linked_successful_live_clinic_payments(): void
    {
        for ($i = 0; $i < 31; $i++) $this->clinicCase();
        $id = $this->online();
        // Unlinked retry rows must never add another payment or case.
        DB::table('gateway_payments')->insert(['payable_id' => $id, 'amount' => 900,
            'payable_type' => (new ClinicAppointment)->getMorphClass()]);
        foreach ([['livemode' => false], ['status' => 'failed'], ['status' => 'cancelled'], ['status' => 'pending'],
            ['status' => 'refunded'], ['context' => 'grooming'], ['context' => 'pos'], ['payable_type' => User::class],
            ['payable_id' => 99999], ['paid_at' => null], ['reconciliation_reason' => 'clinic_already_paid'],
            ['provider' => 'other'], ['currency' => 'USD']] as $gateway) $this->online($gateway);
        foreach ([['paid' => false], ['paid_at' => null], ['consultation_finished_at' => null],
            ['total_amount' => null], ['total_amount' => -100], ['payment_method' => 'online'],
            ['payment_method' => 'gcash'], ['gateway_payment_id' => 99999],
            ['status' => 'for_payment'], ['status' => 'checked_in'], ['status' => 'in_consultation'],
            ['status' => 'cancelled'], ['status' => 'no_show'], ['status' => 'expired'], ['status' => 'archived']] as $attributes) {
            $this->clinicCase($attributes);
        }
        $response = $this->report()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('overview.collections', 16250)->assertJsonPath('overview.completedVisits', 32)
            ->assertJsonPath('overview.paidCases', 32)->assertJsonPath('overview.averageSale', 507.81);
        $this->assertEquals(16250, array_sum(array_column($response->json('performance'), 'collections')));
        $this->assertEquals(32, array_sum(array_column($response->json('performance'), 'visits')));
    }

    public function test_visits_and_services_use_completion_dates_while_collections_use_successful_payment_dates(): void
    {
        $this->clinicCase(['consultation_finished_at' => '2026-09-30 23:59:59', 'paid_at' => '2026-10-01 00:00:00']);
        $this->clinicCase(['consultation_finished_at' => '2026-10-01 00:00:00', 'paid_at' => '2026-11-01 00:00:00']);
        $this->clinicCase(['consultation_finished_at' => '2026-10-31 23:59:59', 'paid_at' => '2026-10-31 23:59:59',
            'archived_at' => '2026-11-02 10:00:00']);
        $this->clinicCase(['consultation_finished_at' => '2026-11-01 00:00:00', 'paid_at' => '2026-11-01 00:00:00']);
        $this->online(['paid_at' => '2026-09-30 23:59:59']); // case.paid_at must not override gateway.paid_at
        $response = $this->report('?period=custom&start=2026-10-01&end=2026-10-31')->assertOk()
            ->assertJsonPath('overview.collections', 1000)->assertJsonPath('overview.completedVisits', 3)
            ->assertJsonPath('overview.paidCases', 2)->assertJsonPath('overview.averageSale', 500)
            ->assertJsonPath('period.timezone', 'Asia/Manila');
        $this->assertEquals(1000, array_sum(array_column($response->json('performance'), 'collections')));
        $this->assertEquals(3, array_sum(array_column($response->json('performance'), 'visits')));
        $this->report('?period=today')->assertOk()->assertJsonPath('period.start', '2026-10-18')->assertJsonPath('period.end', '2026-10-18');
        $this->report('?period=last_7_days')->assertOk()->assertJsonPath('period.start', '2026-10-12');
        $this->report('?period=last_month')->assertOk()->assertJsonPath('period.start', '2026-09-01')->assertJsonPath('period.end', '2026-09-30');
        foreach ([['2026-09-01', '2026-11-30', 'week'], ['2026-01-01', '2026-12-31', 'month']] as [$start, $end, $grouping]) {
            $grouped = $this->report("?period=custom&start=$start&end=$end")->assertOk()->assertJsonPath('period.grouping', $grouping);
            $this->assertEquals(2750, array_sum(array_column($grouped->json('performance'), 'collections')));
            $this->assertEquals(5, array_sum(array_column($grouped->json('performance'), 'visits')));
        }
    }

    public function test_services_count_performed_records_once_and_preserve_snapshots_without_counting_treatment_charge_lines(): void
    {
        DB::table('clinic_services')->insert(['id' => 1, 'name' => 'Renamed service', 'is_active' => false]);
        $case = $this->clinicCase();
        $other = $this->clinicCase();
        $pending = $this->clinicCase(['status' => 'for_payment']);
        $cancelled = $this->clinicCase(['status' => 'cancelled']);
        foreach ([$case, $other, $pending, $cancelled] as $id) {
            DB::table('clinic_case_services')->insert(['clinic_appointment_id' => $id, 'clinic_service_id' => 1,
                'quantity' => 3, 'service_snapshot' => json_encode(['name' => 'Historical service'])]);
        }
        foreach ([['status' => 'ordered'], ['status' => 'cancelled'], ['performed_at' => null], ['cancelled_at' => '2026-10-09 12:00:00']] as $attributes) {
            DB::table('clinic_case_services')->insert($attributes + ['clinic_appointment_id' => $case, 'clinic_service_id' => 1]);
        }
        DB::table('clinic_case_services')->insert(['clinic_appointment_id' => $case, 'service_snapshot' => json_encode(['name' => 'Retired service'])]);
        DB::table('clinic_case_services')->insert(['clinic_appointment_id' => $case, 'clinic_service_id' => 1]);
        foreach (['medicine', 'vaccine', 'service'] as $kind) {
            DB::table('clinic_charges')->insert(['clinic_appointment_id' => $case, 'kind' => $kind, 'description' => 'Do not count again']);
        }
        $response = $this->report()->assertOk()->assertJsonPath('overview.completedVisits', 2)->assertJsonPath('overview.collections', 1000)
            ->assertJsonCount(3, 'services')->assertJsonPath('services.0.name', 'Historical service')->assertJsonPath('services.0.count', 2);
        $this->assertEquals(['Historical service', 'Renamed service', 'Retired service'], array_column($response->json('services'), 'name'));
    }

    public function test_outcomes_use_only_recorded_terminal_states_in_the_period(): void
    {
        $this->clinicCase();
        $this->clinicCase(['status' => 'cancelled']);
        $this->clinicCase(['status' => 'cancelled', 'updated_at' => '2026-09-30 23:59:59']);
        $this->clinicCase(['status' => 'no_show', 'appointment_date' => '2026-10-01']);
        $this->clinicCase(['status' => 'no_show', 'appointment_date' => '2026-09-30']);
        foreach (['for_payment', 'expired', 'archived', 'in_consultation', 'waiting_to_arrive'] as $status) {
            $this->clinicCase(['status' => $status, 'appointment_date' => '2026-10-01']);
        }
        $this->report()->assertOk()->assertJsonPath('outcomes', ['completed' => 1, 'cancelled' => 1, 'noShow' => 1])
            ->assertJsonPath('overview.collections', 500)->assertJsonPath('overview.completedVisits', 1);
    }

    public function test_empty_reports_custom_date_validation_and_access_control(): void
    {
        $this->report()->assertOk()->assertJsonPath('overview.collections', 0)->assertJsonPath('overview.averageSale', 0)
            ->assertJsonPath('overview.completedVisits', 0)->assertJsonPath('services', []);
        foreach (['?period=wrong', '?period=custom', '?period=custom&start=2026-02-30&end=2026-03-01',
            '?period=custom&start=2026-10-31&end=2026-10-01'] as $query) $this->report($query)->assertUnprocessable();
        foreach (['staff', 'customer'] as $role) {
            Sanctum::actingAs((new User)->forceFill(['user_id' => 2, 'role' => $role]));
            $this->report()->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->report()->assertUnauthorized();
    }

    public function test_database_failures_are_concise_and_refresh_reads_new_records(): void
    {
        $this->report()->assertOk()->assertJsonPath('overview.completedVisits', 0);
        $this->clinicCase();
        $this->report()->assertOk()->assertJsonPath('overview.completedVisits', 1);
        $failure = new \Illuminate\Database\QueryException('mysql', 'SELECT private_column FROM private_table', [], new \PDOException('SQL failure'));
        $this->mock(\App\Services\ClinicPerformanceReport::class)->shouldReceive('build')->once()->andThrow($failure);
        $this->report()->assertStatus(500)->assertExactJson(['success' => false, 'message' => 'Unable to load reports. Please try again.']);
    }
}
