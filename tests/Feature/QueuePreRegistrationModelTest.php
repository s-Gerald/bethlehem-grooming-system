<?php

namespace Tests\Feature;

use App\Models\BookingPet;
use App\Models\ClinicSetting;
use App\Models\TimeWindow;
use App\Services\AvailabilityTimeWindowService;
use App\Services\CustomerPreRegistrationAccessService;
use App\Services\DailyPetQueue;
use App\Services\OperationalCapacity;
use App\Services\PreRegistrationExpiry;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueuePreRegistrationModelTest extends TestCase
{
    use \Tests\Support\CreatesPetGroomers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPetGroomers();
        Carbon::setTestNow('2026-10-09 10:00:00');
        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(2);
            $table->time('clinic_open_time')->default('08:00:00');
            $table->time('clinic_close_time')->default('17:00:00');
            $table->time('clinic_prereg_cutoff_time')->default('14:00:00');
            $table->time('grooming_open_time')->default('08:00:00');
            $table->time('grooming_close_time')->default('17:00:00');
            $table->time('grooming_prereg_cutoff_time')->default('14:00:00');
            $table->timestamps();
        });
        ClinicSetting::current();
        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('booking_reference')->nullable();
            $table->string('status')->default('waiting_to_arrive');
            $table->date('booking_date');
            $table->dateTime('dropped_off_at')->nullable();
            $table->integer('queue_number')->nullable();
            $table->integer('number_of_pets')->default(1);
            $table->unsignedInteger('window_id')->nullable();
            $table->time('arrival_end_time')->nullable();
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('grooming_state')->default('not_started');
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('appointment_reference')->nullable();
            $table->string('status')->default('waiting_to_arrive');
            $table->date('appointment_date');
            $table->dateTime('checked_in_at')->nullable();
            $table->integer('queue_number')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type', 50);
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->default('customer');
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('pet_name');
            $table->string('species')->default('Dog');
            $table->unsignedInteger('user_id')->nullable();
        });
        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->integer('max_slots')->default(4);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['notifications', 'customer_notifications', 'clinic_appointments', 'booking_pets', 'bookings', 'pets', 'users', 'time_windows', 'clinic_closures', 'clinic_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('pet_groomers');
        parent::tearDown();
    }

    private function grooming(string $status, int $pets, string $state = 'not_started'): int
    {
        $id = DB::table('bookings')->insertGetId([
            'booking_date' => now()->toDateString(),
            'status' => $status,
            'number_of_pets' => $pets,
        ], 'booking_id');
        foreach (range(1, $pets) as $pet) {
            DB::table('booking_pets')->insert([
                'booking_id' => $id,
                'grooming_state' => $state,
                'grooming_start_time' => $state === 'in_progress' ? now() : null,
                'grooming_end_time' => $state === 'finished' ? now() : null,
            ]);
        }
        return $id;
    }

    private function clinic(string $status): int
    {
        return DB::table('clinic_appointments')->insertGetId([
            'appointment_date' => now()->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_registration_day_uses_hours_breaks_and_closures_without_a_window(): void
    {
        $days = app(\App\Services\RegistrationServiceDay::class);
        foreach (['clinic', 'grooming'] as $service) {
            foreach (['07:00:00', '10:00:00', '12:30:00', '16:59:00'] as $time) {
                Carbon::setTestNow("2026-10-09 {$time}");
                $this->assertSame('2026-10-09', $days->resolve($service));
            }
            Carbon::setTestNow('2026-10-09 17:00:00');
            $this->assertSame('2026-10-10', $days->resolve($service));
        }
        DB::table('clinic_closures')->insert(['type' => 'blocked_date', 'start_date' => '2026-10-10', 'end_date' => '2026-10-15']);
        $this->assertSame('2026-10-16', $days->resolve('clinic'));
        Carbon::setTestNow('2026-10-09 12:30:00');
        $this->assertFalse($days->acceptingCheckIns('grooming'));
        Carbon::setTestNow('2026-10-09 13:00:00');
        $this->assertTrue($days->acceptingCheckIns('grooming'));
        DB::table('clinic_closures')->insert(['type' => 'stop_today', 'start_date' => '2026-10-09', 'end_date' => '2026-10-09']);
        $this->assertSame('2026-10-16', $days->resolve('grooming'));
        $this->assertFalse($days->acceptingCheckIns('clinic'));
    }

    public function test_review_queue_count_is_service_specific_and_excludes_nonwaiting_pets(): void
    {
        $this->grooming('checked_in', 2);
        $this->grooming('in_progress', 1);
        $this->grooming('in_progress', 3, 'in_progress');
        $this->grooming('checked_in', 1, 'cancelled');
        $this->grooming('checked_in', 1, 'finished');
        foreach (['waiting_to_arrive', 'completed', 'cancelled', 'expired'] as $status) {
            $this->grooming($status, 4);
            $this->clinic($status);
        }
        $this->clinic('checked_in');
        $this->clinic('in_consultation');
        $this->getJson('/api/pre-registration/queue-info?service=grooming')->assertOk()
            ->assertJsonPath('waiting_count', 3)->assertJsonPath('service_day', '2026-10-09')
            ->assertJsonMissingPath('pets')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/pre-registration/queue-info?service=clinic')->assertOk()->assertJsonPath('waiting_count', 1);
        Carbon::setTestNow('2026-10-09 17:00:00');
        $this->getJson('/api/pre-registration/queue-info?service=clinic')->assertOk()
            ->assertJsonPath('waiting_count', null)->assertJsonPath('service_day', '2026-10-10');
    }

    public function test_clinic_cases_do_not_change_grooming_arrival_windows(): void
    {
        $settings = ClinicSetting::current();
        app(AvailabilityTimeWindowService::class)->sync($settings);
        $before = app(AvailabilityTimeWindowService::class)->preferredWindows($settings, 'grooming', now()->toDateString())->all();
        for ($index = 0; $index < 25; $index++) {
            $this->clinic('checked_in');
            $this->clinic('in_consultation');
        }
        $after = app(AvailabilityTimeWindowService::class)->preferredWindows($settings, 'grooming', now()->toDateString())->all();
        $this->assertSame($before, $after);
    }

    public function test_grooming_capacity_counts_on_site_pets_until_pickup_and_waiting_stays_combined(): void
    {
        $this->grooming('checked_in', 2);
        $this->grooming('in_progress', 1, 'in_progress');
        $this->clinic('checked_in');
        $this->clinic('in_consultation');
        foreach (['waiting_to_arrive', 'expired', 'cancelled', 'completed', 'archived'] as $status) {
            $this->grooming($status, 3);
            $this->clinic($status);
        }
        $this->grooming('in_progress', 1, 'finished');
        foreach (['for_payment', 'for_pickup', 'released'] as $status) {
            $this->grooming($status, 1, 'finished');
        }
        $this->clinic('for_payment');
        $capacity = app(OperationalCapacity::class);
        $this->assertSame(7, $capacity->snapshot()['used']);
        $this->assertArrayNotHasKey('by_service', $capacity->snapshot());
        $this->assertSame(20, $capacity->snapshot()['max']);
        $this->assertSame(3, $capacity->waitingCount());
        // Pets from yesterday still occupy space until physical pickup.
        DB::table('bookings')->where('status', 'in_progress')->update(['booking_date' => '2026-10-08']);
        $this->assertSame(7, $capacity->snapshot()['used']);
        DB::table('clinic_appointments')->update(['status' => 'completed']);
        $this->assertSame(7, $capacity->snapshot()['used']);
        $this->assertSame(2, $capacity->waitingCount());
        DB::table('bookings')->where('status', 'released')->update(['status' => 'archived']);
        $this->assertSame(6, $capacity->snapshot()['used']);
    }

    public function test_full_grooming_capacity_allows_clinic_and_is_freed_only_at_pickup(): void
    {
        $booking = $this->grooming('in_progress', 1, 'in_progress');
        $this->grooming('released', 19, 'finished');
        foreach (range(1, 8) as $pet) {
            $this->clinic('in_consultation');
        }
        $capacity = app(OperationalCapacity::class);
        $this->assertTrue($capacity->snapshot()['is_full']);
        app(DailyPetQueue::class)->runForDate(now()->toDateString(), fn () => $capacity->assertCanAccept('clinic', 1));
        try {
            app(DailyPetQueue::class)->runForDate(now()->toDateString(), fn () => $capacity->assertCanAccept('grooming', 1));
            $this->fail('Full Grooming capacity must reject Grooming intake.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('check_in', $exception->errors());
        }
        DB::table('booking_pets')->where('booking_id', $booking)->orderBy('booking_pet_id')->limit(1)
            ->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        $this->assertSame(20, $capacity->snapshot()['used']);
        DB::table('bookings')->where('booking_id', $booking)->update(['status' => 'for_payment']);
        $this->assertSame(20, $capacity->snapshot()['used']);
        DB::table('bookings')->where('booking_id', $booking)->update(['status' => 'released']);
        $this->assertSame(20, $capacity->snapshot()['used']);
        DB::table('bookings')->where('booking_id', $booking)->update(['status' => 'archived']);
        $this->assertSame(19, $capacity->snapshot()['used']);
        app(DailyPetQueue::class)->runForDate(now()->toDateString(), fn () => $capacity->assertCanAccept('grooming', 1));
        $this->expectException(ValidationException::class);
        app(DailyPetQueue::class)->runForDate(now()->toDateString(), fn () => $capacity->assertCanAccept('grooming', 2));
    }

    public function test_example_has_six_grooming_pets_on_site_and_five_waiting_across_services(): void
    {
        $this->grooming('checked_in', 3);
        $this->grooming('in_progress', 2, 'in_progress');
        $this->grooming('released', 1, 'finished');
        $this->clinic('checked_in');
        $this->clinic('checked_in');
        $this->assertSame(6, app(OperationalCapacity::class)->snapshot()['used']);
        $this->assertSame(5, app(OperationalCapacity::class)->waitingCount());
    }

    public function test_legacy_pickup_status_requires_intake_evidence_without_losing_real_pickup_pets(): void
    {
        $unused = $this->grooming('for_pickup', 1);
        DB::table('bookings')->where('booking_id', $unused)->update(['booking_date' => '2026-04-17']);
        $capacity = app(OperationalCapacity::class);
        $this->assertSame(0, $capacity->snapshot()['used']);
        $this->assertDatabaseHas('bookings', ['booking_id' => $unused, 'status' => 'for_pickup']);

        $arrived = $this->grooming('for_pickup', 2);
        DB::table('bookings')->where('booking_id', $arrived)->update(['dropped_off_at' => now()->subDay()]);
        $this->assertSame(2, $capacity->snapshot()['used']);

        // Older workflows can lack a drop-off timestamp but retain Grooming activity.
        $finished = $this->grooming('for_pickup', 1, 'finished');
        $this->assertSame(3, $capacity->snapshot()['used']);
        $this->assertSame(0, $capacity->waitingCount());
        DB::table('bookings')->whereIn('booking_id', [$arrived, $finished])->update(['status' => 'archived']);
        $this->assertSame(0, $capacity->snapshot()['used']);
        $this->assertDatabaseCount('booking_pets', 4);
    }

    public function test_expiry_ignores_preferred_time_and_releases_ongoing_access_after_midnight(): void
    {
        DB::table('users')->insert(['user_id' => 22, 'first_name' => 'Gerald', 'last_name' => 'Senining']);
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi', 'user_id' => 22]);
        $grooming = $this->grooming('waiting_to_arrive', 1);
        $clinic = $this->clinic('waiting_to_arrive');
        DB::table('bookings')->where('booking_id', $grooming)->update(['user_id' => 22]);
        DB::table('booking_pets')->where('booking_id', $grooming)->update(['pet_id' => 1]);
        DB::table('clinic_appointments')->where('id', $clinic)->update(['user_id' => 22, 'pet_id' => 1]);
        Carbon::setTestNow('2026-10-09 23:59:59');
        $this->assertSame(0, app(PreRegistrationExpiry::class)->expire());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertFalse(app(CustomerPreRegistrationAccessService::class)->forUser(22)['allowed']);
        Carbon::setTestNow('2026-10-10 00:00:00');
        $this->assertTrue(app(CustomerPreRegistrationAccessService::class)->forUser(22)['allowed']);
        $this->assertDatabaseHas('bookings', ['booking_id' => $grooming, 'status' => 'expired']);
        $this->assertDatabaseHas('clinic_appointments', ['id' => $clinic, 'status' => 'expired']);
        $this->assertDatabaseHas('notifications', ['type' => 'no_show', 'booking_id' => $grooming, 'is_read' => false]);
        $this->assertDatabaseHas('notifications', ['type' => 'clinic_no_show', 'clinic_appointment_id' => $clinic, 'is_read' => false]);
        $this->assertSame(0, app(PreRegistrationExpiry::class)->expire());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('customer_notifications', 1);
        foreach (DB::table('notifications')->get() as $notification) {
            foreach (['Gerald Senining', 'Mochi', 'Oct 9', 'No check-in recorded', $notification->type === 'no_show' ? 'Grooming' : 'Clinic'] as $term) {
                $this->assertStringContainsString($term, $notification->message);
            }
        }
        $this->assertSame(0, app(OperationalCapacity::class)->snapshot()['used']);
    }

    public function test_expiry_only_notifies_eligible_records_on_their_latest_date(): void
    {
        foreach (['checked_in', 'cancelled', 'completed', 'archived', 'expired', 'no_show', 'in_progress', 'for_payment'] as $status) {
            $this->grooming($status, 1);
            $this->clinic($status);
        }
        $arrived = $this->grooming('waiting_to_arrive', 1);
        DB::table('bookings')->where('booking_id', $arrived)->update(['dropped_off_at' => now()]);
        $clinicArrived = $this->clinic('waiting_to_arrive');
        DB::table('clinic_appointments')->where('id', $clinicArrived)->update(['checked_in_at' => now()]);
        $rescheduled = $this->grooming('waiting_to_arrive', 1);
        DB::table('bookings')->where('booking_id', $rescheduled)->update(['booking_date' => '2026-10-11', 'user_id' => 22]);
        $clinicRescheduled = $this->clinic('waiting_to_arrive');
        DB::table('clinic_appointments')->where('id', $clinicRescheduled)->update(['appointment_date' => '2026-10-11', 'user_id' => 22]);
        Carbon::setTestNow('2026-10-10 00:00:00');
        $this->assertSame(0, app(PreRegistrationExpiry::class)->expire());
        $this->assertDatabaseCount('notifications', 0);
        Carbon::setTestNow('2026-10-12 00:00:00');
        $this->assertSame(0, app(PreRegistrationExpiry::class)->expire(23));
        $this->assertSame(2, app(PreRegistrationExpiry::class)->expire(22));
        $this->assertDatabaseCount('notifications', 2);
        foreach (DB::table('notifications')->pluck('message') as $message) {
            $this->assertStringContainsString('Oct 11', $message);
        }
        $this->assertSame(0, app(PreRegistrationExpiry::class)->expire());
    }

    public function test_notification_failure_rolls_back_expiry_for_both_services(): void
    {
        foreach (['grooming', 'clinic'] as $service) {
            $id = $service === 'grooming' ? $this->grooming('waiting_to_arrive', 1) : $this->clinic('waiting_to_arrive');
            $table = $service === 'grooming' ? 'bookings' : 'clinic_appointments';
            $key = $service === 'grooming' ? 'booking_id' : 'id';
            Carbon::setTestNow(now()->addDay());
            DB::unprepared("CREATE TRIGGER reject_no_show BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'notification failure'); END");
            try {
                app(PreRegistrationExpiry::class)->expire();
                $this->fail('Notification insert failure must roll back expiry.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('notification failure', $exception->getMessage());
            } finally {
                DB::unprepared('DROP TRIGGER reject_no_show');
            }
            $this->assertDatabaseHas($table, [$key => $id, 'status' => 'waiting_to_arrive']);
            $this->assertDatabaseCount('notifications', 0);
            DB::table($table)->where($key, $id)->update(['status' => 'cancelled']);
        }
    }

    public function test_overlapping_expiry_calls_create_one_event_per_registration_on_sqlite(): void
    {
        $directory = sys_get_temp_dir().'/bethlehem-expiry-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/expiry.sqlite';
        $token = bin2hex(random_bytes(16));
        file_put_contents($directory.'/owner', $token);
        $first = $second = $connection = $probe = $contender = null;
        try {
            // Probe this Laravel/PDO combination: a config value or PHP version alone is not proof.
            $probePath = $directory.'/probe.sqlite';
            $probe = new \Illuminate\Database\SQLiteConnection(new \PDO('sqlite:'.$probePath), $probePath, '', ['transaction_mode' => 'IMMEDIATE']);
            $contender = new \PDO('sqlite:'.$probePath);
            $contender->exec('PRAGMA busy_timeout=0');
            $probe->beginTransaction();
            $writerLocked = false;
            try {
                $contender->exec('BEGIN IMMEDIATE');
                $contender->exec('ROLLBACK');
            } catch (\PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 5) throw $exception;
                $writerLocked = true;
            }
            $pdoTracksTransaction = $probe->getPdo()->inTransaction();
            $probe->rollBack();
            $probe->disconnect();
            $probe = $contender = null;
            if (! $writerLocked || ! $pdoTracksTransaction) {
                $this->markTestSkipped('This Laravel/PDO SQLite combination does not enforce and track IMMEDIATE transactions. Run this test with a compatible portable PHP runtime.');
            }

            DB::table('users')->insert(['user_id' => 22, 'first_name' => 'Gerald', 'last_name' => 'Senining']);
            DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi', 'user_id' => 22]);
            $grooming = $this->grooming('waiting_to_arrive', 1);
            $clinic = $this->clinic('waiting_to_arrive');
            DB::table('bookings')->where('booking_id', $grooming)->update(['user_id' => 22]);
            DB::table('booking_pets')->where('booking_id', $grooming)->update(['pet_id' => 1]);
            DB::table('clinic_appointments')->where('id', $clinic)->update(['user_id' => 22, 'pet_id' => 1]);
            foreach (['cancelled', 'completed', 'checked_in', 'expired', 'archived', 'waiting_to_arrive'] as $status) {
                $booking = $this->grooming($status, 1);
                $appointment = $this->clinic($status);
                if ($status === 'waiting_to_arrive') {
                    DB::table('bookings')->where('booking_id', $booking)->update(['booking_date' => '2026-10-11']);
                    DB::table('clinic_appointments')->where('id', $appointment)->update(['appointment_date' => '2026-10-11']);
                }
            }
            $arrivedBooking = $this->grooming('waiting_to_arrive', 1);
            $arrivedAppointment = $this->clinic('waiting_to_arrive');
            DB::table('bookings')->where('booking_id', $arrivedBooking)->update(['dropped_off_at' => now()]);
            DB::table('clinic_appointments')->where('id', $arrivedAppointment)->update(['checked_in_at' => now()]);
            $beforeBookings = DB::table('bookings')->where('booking_id', '!=', $grooming)->orderBy('booking_id')->get()->toArray();
            $beforeClinic = DB::table('clinic_appointments')->where('id', '!=', $clinic)->orderBy('id')->get()->toArray();
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                $database = $argv[1];
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-expiry-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'expiry.sqlite' || !is_file($database)
                    || !hash_equals(file_get_contents($directory.'/owner'), $argv[3])) {
                    throw new RuntimeException('Worker requires this run\'s owned disposable SQLite database.');
                }
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
                Illuminate\Support\Facades\DB::purge('sqlite');
                $connection = Illuminate\Support\Facades\DB::connection();
                if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== $database) {
                    throw new RuntimeException('Worker resolved an unexpected database.');
                }
                if (realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) {
                    throw new RuntimeException('SQLite opened a file other than this run\'s disposable database.');
                }
                Carbon\Carbon::setTestNow('2026-10-10 00:00:00');
                $role = $argv[2];
                $timing = [];
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Expiry worker barrier timed out: '.basename($file));
                };
                $connection->beforeStartingTransaction(function () use (&$timing, $role, $directory, $database, $wait) {
                    if ($role !== 'second' || isset($timing['attempted_at'])) return;
                    $wait($directory.'/first-lock');
                    // A real second connection must observe SQLITE_BUSY while the first expiry owns the writer lock.
                    $probe = new PDO('sqlite:'.$database);
                    $probe->exec('PRAGMA busy_timeout=0');
                    try {
                        $probe->exec('BEGIN IMMEDIATE');
                        $probe->exec('ROLLBACK');
                        throw new RuntimeException('First expiry did not hold a SQLite writer lock.');
                    } catch (PDOException $exception) {
                        if (($exception->errorInfo[1] ?? null) !== 5) throw $exception;
                        $timing['busy_observed'] = true;
                    }
                    $timing['attempted_at'] = microtime(true);
                    touch($directory.'/second-attempt');
                });
                $app['events']->listen(Illuminate\Database\Events\TransactionBeginning::class,
                    function () use (&$timing, $role, $directory, $connection, $wait) {
                        if (isset($timing['acquired_at'])) return;
                        $timing['acquired_at'] = microtime(true);
                        if (!$connection->getPdo()->inTransaction()) throw new RuntimeException('PDO did not track the transaction.');
                        if ($role === 'first') {
                            touch($directory.'/first-lock');
                            $wait($directory.'/second-attempt');
                            usleep(200000); // Keep the first transaction open while the second expiry begins its blocking call.
                        }
                    });
                $app['events']->listen(Illuminate\Database\Events\TransactionCommitting::class,
                    function () use (&$timing) { $timing['release_started_at'] ??= microtime(true); });
                $expired = app(App\Services\PreRegistrationExpiry::class)->expire();
                $repeated = app(App\Services\PreRegistrationExpiry::class)->expire();
                echo json_encode(['pid' => getmypid(), 'php' => PHP_VERSION, 'driver' => $connection->getDriverName(),
                    'database' => $connection->getDatabaseName(),
                    'expired' => $expired, 'repeated' => $repeated, 'timing' => $timing], JSON_THROW_ON_ERROR);
                PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            $first = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'first', $token], base_path(), $environment);
            $second = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'second', $token], base_path(), $environment);
            $first->setTimeout(15);
            $second->setTimeout(15);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $results = array_map(fn ($process) => json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), [$first, $second]);
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            foreach ($results as $result) {
                $this->assertSame($database, $result['database']);
                $this->assertSame('sqlite', $result['driver']);
                $this->assertSame(PHP_VERSION, $result['php']);
                $this->assertSame(0, $result['repeated']);
            }
            $this->assertTrue($results[1]['timing']['busy_observed']);
            $this->assertLessThan($results[1]['timing']['attempted_at'], $results[0]['timing']['acquired_at']);
            $this->assertLessThan($results[0]['timing']['release_started_at'], $results[1]['timing']['attempted_at']);
            $this->assertGreaterThanOrEqual($results[0]['timing']['release_started_at'], $results[1]['timing']['acquired_at']);
            $this->assertSame(2, array_sum(array_column($results, 'expired')));
            $connection = new \PDO('sqlite:'.$database);
            $events = $connection->query('SELECT * FROM notifications ORDER BY notification_id')->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertCount(2, $events);
            $this->assertSame(['no_show', 'clinic_no_show'], array_column($events, 'type'));
            $this->assertSame($grooming, (int) $events[0]['booking_id']);
            $this->assertSame($clinic, (int) $events[1]['clinic_appointment_id']);
            foreach ($events as $event) {
                foreach (['Gerald Senining', 'Mochi', 'Oct 9', 'No check-in recorded', $event['type'] === 'no_show' ? 'Grooming' : 'Clinic'] as $term) {
                    $this->assertStringContainsString($term, $event['message']);
                }
            }
            $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM customer_notifications')->fetchColumn());
            $this->assertSame('expired', $connection->query('SELECT status FROM bookings WHERE booking_id='.$grooming)->fetchColumn());
            $this->assertSame('expired', $connection->query('SELECT status FROM clinic_appointments WHERE id='.$clinic)->fetchColumn());
            $this->assertEquals($beforeBookings, $connection->query('SELECT * FROM bookings WHERE booking_id != '.$grooming.' ORDER BY booking_id')->fetchAll(\PDO::FETCH_OBJ));
            $this->assertEquals($beforeClinic, $connection->query('SELECT * FROM clinic_appointments WHERE id != '.$clinic.' ORDER BY id')->fetchAll(\PDO::FETCH_OBJ));
        } finally {
            $first?->stop();
            $second?->stop();
            if ($probe?->transactionLevel()) $probe->rollBack();
            $probe?->disconnect();
            $connection = $probe = $contender = null;
            foreach (['expiry.sqlite', 'expiry.sqlite-journal', 'probe.sqlite', 'probe.sqlite-journal', 'first-lock', 'second-attempt', 'owner'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            $this->assertTrue(rmdir($directory), 'Disposable expiry database directory must be removed.');
        }
        $this->assertFileDoesNotExist($database);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_migration_preserves_legacy_history_and_only_normalizes_unused_no_shows(): void
    {
        $unused = $this->grooming('no_show', 2);
        $arrived = $this->grooming('no_show', 1);
        DB::table('bookings')->where('booking_id', $arrived)->update(['dropped_off_at' => now()]);
        $clinic = $this->clinic('no_show');
        $migration = require database_path('migrations/2026_10_09_120000_add_expired_pre_registration_status.php');
        $migration->up();
        $this->assertDatabaseHas('bookings', ['booking_id' => $unused, 'status' => 'expired', 'legacy_pre_registration_status' => 'no_show']);
        $this->assertDatabaseHas('bookings', ['booking_id' => $arrived, 'status' => 'no_show']);
        $this->assertDatabaseHas('clinic_appointments', ['id' => $clinic, 'status' => 'expired', 'legacy_pre_registration_status' => 'no_show']);
        $this->assertDatabaseCount('booking_pets', 3);
        $migration->down();
        $this->assertDatabaseHas('bookings', ['booking_id' => $unused, 'status' => 'expired']);
        $migration->up();
        $this->assertDatabaseHas('bookings', ['booking_id' => $unused, 'status' => 'expired', 'legacy_pre_registration_status' => 'no_show']);
    }

    public function test_preferred_windows_keep_real_restrictions_without_counting_registrations(): void
    {
        foreach (['08:00:00', '11:00:00', '12:00:00', '13:00:00', '16:00:00'] as $time) {
            TimeWindow::create(['window_label' => $time, 'start_time' => $time,
                'end_time' => Carbon::parse($time)->addHour()->format('H:i:s'), 'is_active' => true]);
        }
        $this->grooming('waiting_to_arrive', 30);
        $this->grooming('released', 20, 'finished');
        $service = app(AvailabilityTimeWindowService::class);
        foreach (['clinic', 'grooming'] as $flow) {
            $windows = $service->preferredWindows(ClinicSetting::current(), $flow, now()->toDateString());
            $this->assertSame(['08:00:00', '11:00:00', '13:00:00', '16:00:00'], $windows->pluck('start_time')->all());
            $this->assertTrue($windows[0]['is_past']);
            $this->assertFalse($windows[1]['is_past']);
            $this->assertArrayNotHasKey('is_full', $windows[1]);
            $this->assertArrayNotHasKey('recommended', $windows[1]);
        }
        DB::table('clinic_closures')->insert(['type' => 'stop_today', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]);
        $this->assertTrue($service->preferredWindows(ClinicSetting::current(), 'clinic', now()->toDateString())[1]['is_closed']);
        $this->assertFalse($service->preferredWindows(ClinicSetting::current(), 'grooming', now()->addDay()->toDateString())[1]['is_closed']);
        Carbon::setTestNow('2026-10-09 14:01:00');
        $this->assertFalse($service->preferredWindows(ClinicSetting::current(), 'grooming', now()->toDateString())[3]['is_past']);
    }

    public function test_cutoff_removal_migration_regenerates_later_windows_and_preserves_the_break(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('arrival_end_time'));
        $migration = require database_path('migrations/2026_10_16_000003_remove_pre_registration_cutoffs.php');
        $migration->up();
        $this->assertFalse(Schema::hasColumn('clinic_settings', 'clinic_prereg_cutoff_time'));
        $this->assertFalse(Schema::hasColumn('clinic_settings', 'grooming_prereg_cutoff_time'));
        $this->assertTrue(Schema::hasColumn('bookings', 'arrival_end_time'));
        foreach (['clinic', 'grooming'] as $flow) {
            $windows = app(AvailabilityTimeWindowService::class)->preferredWindows(ClinicSetting::current(), $flow, now()->toDateString());
            $this->assertCount(8, $windows);
            $this->assertSame('16:00:00', $windows->last()['start_time']);
            $this->assertNotContains('12:00:00', $windows->pluck('start_time')->all());
        }
        $migration->down();
        $this->assertTrue(Schema::hasColumn('clinic_settings', 'clinic_prereg_cutoff_time'));
        $this->assertFalse(Schema::hasColumn('bookings', 'arrival_end_time'));
    }

    #[DataProvider('preferredArrivalBoundaries')]
    public function test_preferred_arrival_windows_expire_at_their_end_for_both_services(string $flow, string $time, bool $available): void
    {
        Carbon::setTestNow("2026-10-09 {$time}");
        $settings = ClinicSetting::current();
        $settings->update([
            "{$flow}_open_time" => '18:00:00',
            "{$flow}_close_time" => '22:00:00',
            "{$flow}_prereg_cutoff_time" => '22:00:00',
        ]);
        $window = TimeWindow::create([
            'window_label' => '7:00 PM - 8:00 PM',
            'start_time' => '19:00:00', 'end_time' => '20:00:00', 'is_active' => true,
        ]);
        $service = app(AvailabilityTimeWindowService::class);
        $this->assertSame(! $available, $service->preferredWindows($settings, $flow, '2026-10-09')[0]['is_past']);

        if ($available) {
            $service->assertPreferredArrival($settings, $flow, '2026-10-09', $window);
        } else {
            try {
                $service->assertPreferredArrival($settings, $flow, '2026-10-09', $window);
                $this->fail('An ended arrival window must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame('arrival_window_ended', $exception->response->getData(true)['code']);
            }
        }

        // Tomorrow is unaffected by today's time, even after the same-day cutoff.
        Carbon::setTestNow('2026-10-09 23:00:00');
        $future = $service->preferredWindows($settings, $flow, '2026-10-10')[0];
        $this->assertFalse($future['is_past']);
        $this->assertArrayNotHasKey('is_cutoff', $future);
        $service->assertPreferredArrival($settings, $flow, '2026-10-10', $window);
    }

    public static function preferredArrivalBoundaries(): array
    {
        $cases = [];
        foreach (['clinic', 'grooming'] as $flow) {
            foreach (['18:59:00' => true, '19:00:00' => true, '19:05:00' => true, '19:59:00' => true, '20:00:00' => false] as $time => $available) {
                $cases["{$flow} at {$time}"] = [$flow, $time, $available];
            }
        }
        return $cases;
    }

    public function test_closures_and_operating_hours_still_override_active_arrival_windows(): void
    {
        Carbon::setTestNow('2026-10-09 19:05:00');
        $service = app(AvailabilityTimeWindowService::class);
        $window = TimeWindow::create([
            'window_label' => '7:00 PM - 8:00 PM',
            'start_time' => '19:00:00', 'end_time' => '20:00:00', 'is_active' => true,
        ]);
        foreach (['clinic', 'grooming'] as $flow) {
            foreach (['blocked_date', 'stop_today', 'operating_hours'] as $restriction) {
                $settings = ClinicSetting::current();
                $settings->update([
                    "{$flow}_open_time" => '18:00:00',
                    "{$flow}_close_time" => $restriction === 'operating_hours' ? '19:00:00' : '22:00:00',
                    "{$flow}_prereg_cutoff_time" => $restriction === 'cutoff' ? '19:00:00' : '22:00:00',
                ]);
                if (in_array($restriction, ['blocked_date', 'stop_today'], true)) {
                    DB::table('clinic_closures')->insert([
                        'type' => $restriction, 'start_date' => '2026-10-09', 'end_date' => '2026-10-09',
                    ]);
                    $this->assertTrue($service->preferredWindows($settings, $flow, '2026-10-09')[0]['is_closed']);
                } else {
                    $this->assertCount(0, $service->preferredWindows($settings, $flow, '2026-10-09'));
                }
                try {
                    $service->assertPreferredArrival($settings, $flow, '2026-10-09', $window);
                    $this->fail("{$flow}: {$restriction} must block an active window.");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('window_id', $exception->errors());
                    $this->assertNull($exception->response);
                }
                DB::table('clinic_closures')->delete();
            }
        }
    }

    public function test_stop_today_blocks_intake_without_changing_unused_registrations_and_reopen_restores_intake(): void
    {
        $this->grooming('waiting_to_arrive', 1);
        $this->clinic('waiting_to_arrive');
        $request = \Illuminate\Http\Request::create('/api/admin/clinic/stop-today', 'POST');
        $request->setUserResolver(fn () => new \App\Models\User(['role' => 'admin']));
        $closures = app(\App\Http\Controllers\ClinicClosureController::class);
        $this->assertSame(200, $closures->stopToday($request)->status());
        $this->assertDatabaseHas('bookings', ['status' => 'waiting_to_arrive']);
        $this->assertDatabaseHas('clinic_appointments', ['status' => 'waiting_to_arrive']);
        foreach (['clinic', 'grooming'] as $service) {
            try {
                app(DailyPetQueue::class)->runForDate(now()->toDateString(), fn () => app(OperationalCapacity::class)->assertCanAccept($service, 1));
                $this->fail('Stopped intake must reject both services.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('check_in', $exception->errors());
            }
        }
        $this->assertSame(200, $closures->reopenToday($request)->status());
        app(DailyPetQueue::class)->runForDate(now()->toDateString(), fn () => app(OperationalCapacity::class)->assertCanAccept('clinic', 1));
        Carbon::setTestNow('2026-10-10 00:00:00');
        $this->assertSame(2, app(PreRegistrationExpiry::class)->expire());
    }

    public function test_both_services_send_deduplicated_preferred_arrival_reminders(): void
    {
        $migration = require database_path('migrations/2026_10_09_120000_add_expired_pre_registration_status.php');
        $migration->up();
        DB::table('users')->insert(['user_id' => 22, 'first_name' => 'Test']);
        DB::table('pets')->insert(['pet_id' => 1, 'user_id' => 22, 'pet_name' => 'Bella']);
        $window = TimeWindow::create(['window_label' => '10:00 AM - 11:00 AM', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'is_active' => true]);
        $booking = $this->grooming('waiting_to_arrive', 1);
        DB::table('bookings')->where('booking_id', $booking)->update(['user_id' => 22, 'window_id' => $window->window_id, 'booking_date' => '2026-10-10']);
        DB::table('booking_pets')->where('booking_id', $booking)->update(['pet_id' => 1]);
        $clinic = $this->clinic('waiting_to_arrive');
        DB::table('clinic_appointments')->where('id', $clinic)->update(['user_id' => 22, 'pet_id' => 1, 'window_id' => $window->window_id, 'appointment_date' => '2026-10-10']);
        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->assertSuccessful();
        $this->assertDatabaseCount('customer_notifications', 2);
        $messages = DB::table('customer_notifications')->pluck('message')->all();
        $this->assertStringContainsString('grooming drop-off tomorrow around', $messages[0]);
        $this->assertStringContainsString('clinic arrival tomorrow around', $messages[1]);
        foreach ($messages as $message) {
            $this->assertStringContainsString('queue after check-in', $message);
            $this->assertStringNotContainsString('appointment', $message);
        }
        Carbon::setTestNow('2026-10-10 07:00:00');
        $this->artisan('reminders:send')->assertSuccessful();
        $this->assertDatabaseCount('customer_notifications', 4);
    }

    public function test_both_services_remind_the_valid_day_without_fabricating_an_arrival_time(): void
    {
        (require database_path('migrations/2026_10_09_120000_add_expired_pre_registration_status.php'))->up();
        Carbon::setTestNow('2026-10-09 08:00:00');
        DB::table('users')->insert(['user_id' => 22, 'first_name' => 'Test']);
        DB::table('pets')->insert(['pet_id' => 1, 'user_id' => 22, 'pet_name' => 'Bella']);
        $booking = $this->grooming('waiting_to_arrive', 1);
        DB::table('bookings')->where('booking_id', $booking)->update(['user_id' => 22, 'booking_date' => '2026-10-10']);
        DB::table('booking_pets')->where('booking_id', $booking)->update(['pet_id' => 1]);
        $clinic = $this->clinic('waiting_to_arrive');
        DB::table('clinic_appointments')->where('id', $clinic)->update(['user_id' => 22, 'pet_id' => 1, 'appointment_date' => '2026-10-10']);
        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->assertSuccessful();
        $this->assertDatabaseCount('customer_notifications', 2);
        foreach (DB::table('customer_notifications')->pluck('message') as $message) {
            $this->assertStringContainsString('valid tomorrow', $message);
            $this->assertStringNotContainsString('around', $message);
            $this->assertStringNotContainsString('AM', $message);
        }
    }
}
