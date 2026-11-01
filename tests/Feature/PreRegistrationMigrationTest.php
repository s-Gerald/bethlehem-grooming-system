<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminClinicController;
use App\Models\ClinicSetting;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreRegistrationMigrationTest extends TestCase
{
    public function test_fresh_migrations_preserve_scheduled_expiration_and_existing_database_reruns(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Full historical schema requires the isolated local MySQL runner.');
        }
        require_once __DIR__.'/../Support/CapacityMysqlHarness.php';
        \Tests\Support\assertCapacityConnection(DB::connection());
        $this->assertFalse(Schema::hasTable('migrations'));
        $stages = [];
        $this->app['events']->listen(MigrationEnded::class, function ($event) use (&$stages): void {
            $name = basename((new \ReflectionClass($event->migration))->getFileName());
            if ($name === '2026_10_09_120000_add_expired_pre_registration_status.php') {
                $this->assertFalse(Schema::hasTable('pet_groomers'));
                $this->assertTrue(Schema::hasColumn('bookings', 'legacy_pre_registration_status'));
                $this->assertTrue(Schema::hasColumn('clinic_appointments', 'legacy_pre_registration_status'));
                $this->assertTrue(Schema::hasColumn('customer_notifications', 'clinic_appointment_id'));
                $stages[] = 'status';
            } elseif ($name === '2026_10_16_000004_create_named_pet_groomers.php') {
                $this->assertTrue(Schema::hasTable('pet_groomers'));
                $stages[] = 'groomers';
            }
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame(['status', 'groomers'], $stages);
        $this->assertDatabaseCount('migrations', count(glob(database_path('migrations/*.php'))));
        $this->assertTrue(collect(Schema::getForeignKeys('booking_pets'))
            ->contains(fn ($key) => $key['columns'] === ['groomer_id'] && $key['foreign_table'] === 'pet_groomers'));
        $this->assertTrue(collect(Schema::getForeignKeys('customer_notifications'))
            ->contains(fn ($key) => $key['columns'] === ['clinic_appointment_id'] && $key['foreign_table'] === 'clinic_appointments'));
        $scheduled = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'pre-registrations:expire'))->values();
        $this->assertCount(1, $scheduled);
        $this->assertSame('* * * * *', $scheduled[0]->expression);
        $this->assertSame('Asia/Manila', $scheduled[0]->timezone);
        $this->assertTrue($scheduled[0]->withoutOverlapping);

        try {
            Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'Asia/Manila'));
            ClinicSetting::current()->update(['grooming_close_time' => '16:00:00', 'clinic_close_time' => '18:00:00']);
            $user = DB::table('users')->insertGetId([
                'first_name' => 'Migration', 'last_name' => 'Fixture', 'phone' => '09170000000',
                'email' => 'migration@example.test', 'password_hash' => 'unused-test-password',
            ], 'user_id');
            $pet = DB::table('pets')->insertGetId(['user_id' => $user, 'pet_name' => 'Fixture'], 'pet_id');
            $registrations = [];
            foreach (['grooming', 'clinic'] as $service) {
                $grooming = $service === 'grooming';
                $table = $grooming ? 'bookings' : 'clinic_appointments';
                $key = $grooming ? 'booking_id' : 'id';
                $protections = ['eligible', 'checked_in', 'paid', 'exempt', 'expired'];
                if ($grooming) $protections[] = 'active_grooming';
                foreach ($protections as $protection) {
                    $attributes = [
                        'user_id' => $user,
                        $grooming ? 'booking_reference' : 'appointment_reference' => ($grooming ? 'G-' : 'C-').$protection,
                        $grooming ? 'booking_date' : 'appointment_date' => '2026-11-02',
                        'status' => match ($protection) {
                            'checked_in' => 'checked_in', 'expired' => 'expired', default => 'waiting_to_arrive',
                        },
                        'registration_closes_at' => '2026-11-02 '.($grooming ? '16:00:00' : '18:00:00'),
                        'registration_no_show_exempt_at' => $protection === 'exempt' ? now() : null,
                        'paid' => $protection === 'paid', 'created_at' => now(), 'updated_at' => now(),
                        $grooming ? 'dropped_off_at' : 'checked_in_at' => $protection === 'checked_in' ? now() : null,
                        'queue_number' => $protection === 'checked_in' ? 1 : null,
                    ];
                    if (! $grooming) $attributes += ['pet_id' => $pet, 'appointment_type' => 'pre_registered'];
                    $id = DB::table($table)->insertGetId($attributes, $key);
                    $registrations[$service][$protection] = $id;
                    if ($grooming) DB::table('booking_pets')->insert([
                        'booking_id' => $id, 'pet_id' => $pet,
                        'grooming_state' => $protection === 'active_grooming' ? 'in_progress' : 'not_started',
                        'grooming_start_time' => $protection === 'active_grooming' ? now() : null,
                    ]);
                }
            }

            // Use the scheduled command itself: expiration must not depend on an HTTP request.
            Carbon::setTestNow('2026-11-02 15:59:59');
            $this->artisan('pre-registrations:expire')->expectsOutput('Marked 0 Online Registration(s) No Show.')->assertSuccessful();
            $this->assertCount(4, app(AdminBookingController::class)->index(Request::create('/'))->getData(true)['incomingList']);
            $this->assertCount(3, app(AdminClinicController::class)->index()->getData(true)['incoming']);
            Carbon::setTestNow('2026-11-02 16:00:00');
            $this->artisan('pre-registrations:expire')->expectsOutput('Marked 1 Online Registration(s) No Show.')->assertSuccessful();
            $this->assertDatabaseHas('bookings', ['booking_id' => $registrations['grooming']['eligible'], 'status' => 'no_show']);
            $this->assertDatabaseHas('clinic_appointments', ['id' => $registrations['clinic']['eligible'], 'status' => 'waiting_to_arrive']);
            Carbon::setTestNow('2026-11-02 17:59:59');
            $this->artisan('pre-registrations:expire')->expectsOutput('Marked 0 Online Registration(s) No Show.')->assertSuccessful();
            Carbon::setTestNow('2026-11-02 18:00:00');
            $this->artisan('pre-registrations:expire')->expectsOutput('Marked 1 Online Registration(s) No Show.')->assertSuccessful();
            $this->assertDatabaseHas('clinic_appointments', ['id' => $registrations['clinic']['eligible'], 'status' => 'no_show']);
            $this->assertDatabaseHas('notifications', ['type' => 'no_show', 'booking_id' => $registrations['grooming']['eligible']]);
            $this->assertDatabaseHas('notifications', ['type' => 'clinic_no_show', 'clinic_appointment_id' => $registrations['clinic']['eligible']]);
            $this->assertDatabaseHas('customer_notifications', ['type' => 'grooming_no_show', 'booking_id' => $registrations['grooming']['eligible']]);
            $this->artisan('pre-registrations:expire')->expectsOutput('Marked 0 Online Registration(s) No Show.')->assertSuccessful();
            $this->assertSame(2, DB::table('activity_logs')->where('action', 'pre_registration_no_show')->count());
            $this->assertSame(2, DB::table('notifications')->whereIn('type', ['no_show', 'clinic_no_show'])->count());
            $this->assertSame(1, DB::table('customer_notifications')->where('type', 'grooming_no_show')->count());
            $groomingIncoming = app(AdminBookingController::class)->index(Request::create('/'))->getData(true)['incomingList'];
            $clinicIncoming = app(AdminClinicController::class)->index()->getData(true)['incoming'];
            $this->assertNotContains($registrations['grooming']['eligible'], array_column($groomingIncoming, 'id'));
            $this->assertNotContains($registrations['clinic']['eligible'], array_column($clinicIncoming, 'id'));
            foreach (['grooming', 'clinic'] as $service) {
                foreach (['checked_in', 'paid', 'exempt'] as $protection) {
                    $this->assertDatabaseHas($service === 'grooming' ? 'bookings' : 'clinic_appointments', [
                        $service === 'grooming' ? 'booking_id' : 'id' => $registrations[$service][$protection],
                        'status' => $protection === 'checked_in' ? 'checked_in' : 'waiting_to_arrive',
                    ]);
                }
                $this->assertDatabaseHas($service === 'grooming' ? 'bookings' : 'clinic_appointments', [
                    $service === 'grooming' ? 'booking_id' : 'id' => $registrations[$service]['expired'], 'status' => 'expired',
                ]);
            }
            $this->assertDatabaseHas('bookings', ['booking_id' => $registrations['grooming']['active_grooming'], 'status' => 'waiting_to_arrive']);
            $this->assertDatabaseCount('bookings', 6);
            $this->assertDatabaseCount('booking_pets', 6);
            $this->assertDatabaseCount('clinic_appointments', 5);

            // An already migrated installation must keep all rows and migration history untouched.
            $tables = ['migrations', 'bookings', 'booking_pets', 'clinic_appointments', 'notifications', 'customer_notifications', 'activity_logs'];
            $before = [];
            foreach ($tables as $table) $before[$table] = DB::table($table)->get()->toArray();
            $this->artisan('migrate', ['--force' => true])->expectsOutputToContain('Nothing to migrate')->assertSuccessful();
            foreach ($tables as $table) $this->assertEquals($before[$table], DB::table($table)->get()->toArray(), $table);
        } finally {
            Carbon::setTestNow();
        }
    }
}
