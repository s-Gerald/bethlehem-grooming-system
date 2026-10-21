<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Models\BookingPet;
use App\Models\GatewayPayment;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) getenv('DASHBOARD_TEST_DATABASE');
        if ($database === '') $this->markTestSkipped('Run through tests/dashboard-summary-mysql.php.');
        $connection = DB::connection();
        if (! preg_match('/\Abethlehem_dashboard_[a-f0-9]{12}\z/', $database)
            || $connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', '::1'], true)
            || $connection->selectOne('SELECT DATABASE() AS name')->name !== $database) {
            throw new \RuntimeException('Refusing a non-disposable Dashboard test connection.');
        }
        DB::beginTransaction();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $user = User::forceCreate(['first_name' => 'Dashboard', 'last_name' => 'Admin',
            'phone' => '09000000001', 'email' => 'dashboard@example.test', 'password_hash' => 'unused', 'role' => 'admin']);
        Sanctum::actingAs($user, ['*']);
    }

    protected function tearDown(): void
    {
        if (getenv('DASHBOARD_TEST_DATABASE') && DB::transactionLevel()) DB::rollBack();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booking(string $reference, string $status, ?int $queue = null, string $date = '2026-10-21', array $attributes = []): int
    {
        return DB::table('bookings')->insertGetId([...$attributes, 'booking_reference' => $reference,
            'status' => $status, 'queue_number' => $queue, 'booking_date' => $date], 'booking_id');
    }

    public function test_empty_summary_returns_real_zero_values_over_the_authorized_route(): void
    {
        $this->getJson('/api/admin/dashboard/summary')->assertOk()
            ->assertJsonPath('summary.today', 0)->assertJsonPath('summary.revenueToday', 0)
            ->assertJsonPath('summary.waitingNow', 0)->assertJsonPath('capacity.current', 0)
            ->assertJsonPath('activeTodayCount', 0)->assertJsonCount(0, 'queuePreview');
    }

    public function test_populated_queue_orders_started_visits_before_waiting_without_losing_queue_order(): void
    {
        $ids = [];
        foreach (['checked_in', 'checked_in', 'in_progress', 'checked_in', 'checked_in', 'checked_in', 'checked_in'] as $i => $status) {
            $id = $ids[] = $this->booking('QUEUE-'.$i, $status, $i + 1,
                attributes: ['dropped_off_at' => $i === 0 ? '2026-10-21 09:45:00' : null]);
            DB::table('booking_pets')->insert(['booking_id' => $id,
                'grooming_state' => $i === 1 ? BookingPet::GROOMING_STATE_IN_PROGRESS : BookingPet::GROOMING_STATE_NOT_STARTED,
                'grooming_start_time' => $i === 1 ? now() : null]);
        }
        $response = $this->getJson('/api/admin/dashboard/summary')->assertOk();
        $this->assertSame([$ids[1], $ids[2], $ids[0], $ids[3], $ids[4]], array_column($response->json('queuePreview'), 'id'));
        $response->assertJsonPath('queuePreview.0.status', 'in-progress')
            ->assertJsonPath('queuePreview.1.status', 'in-progress')->assertJsonPath('queuePreview.2.status', 'queued')
            ->assertJsonPath('queuePreview.2.dropOffTime', '9:45 AM')->assertJsonPath('queuePreview.0.dropOffTime', null)
            ->assertJsonPath('capacity.current', 7)->assertJsonPath('summary.waitingNow', 6)->assertJsonPath('activeTodayCount', 7);
    }

    public function test_summary_preserves_collections_and_completed_counts_with_current_migrations(): void
    {
        $id = $this->booking('COMPLETED', 'archived', attributes: ['grooming_finished_at' => now(), 'number_of_pets' => 3]);
        foreach ([[125, 'paid', '2026-10-21'], [250, 'paid', '2026-10-21'], [900, 'pending', '2026-10-21'], [800, 'paid', '2026-10-20']] as [$amount, $status, $day]) {
            DB::table('payments')->insert(['booking_id' => $id, 'total_amount' => $amount,
                'payment_method' => 'cash', 'payment_status' => $status, 'paid_at' => $day.' 09:00:00']);
        }
        $this->getJson('/api/admin/dashboard/summary')->assertOk()->assertJsonPath('summary.today', '3')
            ->assertJsonPath('summary.revenueToday', 375)->assertJsonPath('summary.revenuePaymentCount', 2);
    }

    public function test_summary_excludes_test_gateway_money_and_includes_live_and_manual_collections(): void
    {
        $id = $this->booking('COLLECTIONS', 'released');
        foreach ([false, true] as $live) {
            $gateway = GatewayPayment::create(['provider' => 'paymongo', 'payable_type' => \App\Models\Booking::class,
                'payable_id' => $id, 'context' => 'grooming', 'reference' => $live ? 'LIVE' : 'TEST',
                'amount' => $live ? 100 : 999, 'status' => 'paid', 'livemode' => $live, 'snapshot' => []]);
            DB::table('payments')->insert(['booking_id' => $id, 'gateway_payment_id' => $gateway->id,
                'total_amount' => $gateway->amount, 'payment_status' => 'paid', 'paid_at' => now()]);
        }
        DB::table('payments')->insert(['booking_id' => $id, 'total_amount' => 250, 'payment_status' => 'paid', 'paid_at' => now()]);
        $this->getJson('/api/admin/dashboard/summary')->assertOk()->assertJsonPath('summary.revenueToday', 350)
            ->assertJsonPath('summary.revenuePaymentCount', 2);
    }

    public function test_repeated_summary_preserves_day_boundary_expiry_and_notification_categories(): void
    {
        $expired = $this->booking('EXPIRED', 'waiting_to_arrive', date: '2026-10-20');
        $today = $this->booking('TODAY', 'waiting_to_arrive');
        $future = $this->booking('FUTURE', 'waiting_to_arrive', date: '2026-10-22');
        $clinic = DB::table('clinic_appointments')->insertGetId(['appointment_reference' => 'CLINIC-EXPIRED',
            'appointment_date' => '2026-10-20', 'status' => 'waiting_to_arrive']);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/api/admin/dashboard/summary')->assertOk()->assertJsonPath('activeTodayCount', 1);
        }
        $this->assertSame('expired', DB::table('bookings')->where('booking_id', $expired)->value('status'));
        $this->assertSame(2, DB::table('bookings')->whereIn('booking_id', [$today, $future])->where('status', 'waiting_to_arrive')->count());
        $this->assertSame(1, Notification::where('booking_id', $expired)->where('type', Notification::TYPE_NO_SHOW)->count());
        $this->assertSame(1, Notification::where('clinic_appointment_id', $clinic)->where('type', Notification::TYPE_CLINIC_NO_SHOW)->count());
        $this->assertSame('grooming', Notification::categoryForType(Notification::TYPE_NO_SHOW));
        $this->assertSame('clinic', Notification::categoryForType(Notification::TYPE_CLINIC_NO_SHOW));
    }

    public function test_summary_query_budget_is_bounded_as_queue_grows(): void
    {
        $controller = new AdminBookingController;
        DB::enableQueryLog();
        $start = hrtime(true);
        $controller->dashboardSummary();
        $empty = DB::getQueryLog();
        $emptyMs = (hrtime(true) - $start) / 1e6;
        DB::disableQueryLog();
        $owner = User::first()->user_id;
        for ($i = 0; $i < 5; $i++) {
            $id = $this->booking('BUDGET-'.$i, 'checked_in', $i + 1, attributes: ['user_id' => $owner]);
            $pet = DB::table('pets')->insertGetId(['user_id' => $owner, 'pet_name' => 'Pet '.$i], 'pet_id');
            DB::table('booking_pets')->insert(['booking_id' => $id, 'pet_id' => $pet]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $response = $controller->dashboardSummary();
        $populated = DB::getQueryLog();
        $populatedMs = (hrtime(true) - $start) / 1e6;
        DB::disableQueryLog();
        $this->assertCount(5, $response->getData(true)['queuePreview']);
        $this->assertLessThanOrEqual(count($empty) + 3, count($populated));
        for ($i = 5; $i < 30; $i++) {
            $id = $this->booking('BUDGET-'.$i, 'checked_in', $i + 1, attributes: ['user_id' => $owner]);
            DB::table('booking_pets')->insert(['booking_id' => $id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $controller->dashboardSummary();
        $larger = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(count($populated), $larger, 'The five-card preview must not add queries as the queue grows.');
        fwrite(STDOUT, sprintf("\nSummary MySQL: empty=%d queries/%.2f ms; five visits=%d queries/%.2f ms; thirty visits=%d queries\n", count($empty), $emptyMs, count($populated), $populatedMs, $larger));
    }
}
