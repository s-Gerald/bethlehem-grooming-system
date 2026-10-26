<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroomingEstimateRetirementMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'mysql') {
            require_once __DIR__.'/../Support/CapacityMysqlHarness.php';
            \Tests\Support\assertCapacityConnection(DB::connection());
        } elseif (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Migration fixtures require in-memory SQLite or the owned local MySQL test runner.');
        }
    }

    public function test_upgrade_removes_only_predictions_and_preserves_completed_and_future_visits(): void
    {
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->string('grooming_preference');
            $table->boolean('haircut_requested');
            $table->text('special_instructions');
            $table->integer('groomer_id');
            $table->date('pet_queue_date');
            $table->integer('pet_queue_number');
            $table->string('grooming_state');
            $table->timestamp('grooming_start_time')->nullable();
            $table->timestamp('grooming_end_time')->nullable();
            $table->integer('grooming_estimate_min')->nullable();
            $table->integer('grooming_estimate_max')->nullable();
            $table->json('grooming_estimate_factors')->nullable();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->decimal('base_price', 10, 2);
            $table->integer('duration_minutes')->nullable();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->date('booking_date');
            $table->time('arrival_end_time');
            $table->string('status');
            $table->timestamp('dropped_off_at')->nullable();
            $table->timestamp('grooming_started_at')->nullable();
            $table->timestamp('grooming_finished_at')->nullable();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->decimal('total_amount', 10, 2);
            $table->timestamp('paid_at');
        });
        $session = ['booking_pet_id' => 1, 'grooming_preference' => 'regular_trim', 'haircut_requested' => false,
            'special_instructions' => 'Keep face tidy', 'groomer_id' => 2, 'pet_queue_date' => '2026-10-25',
            'pet_queue_number' => 12, 'grooming_state' => 'finished', 'grooming_start_time' => '2026-10-25 09:00:00',
            'grooming_end_time' => '2026-10-25 11:18:00'];
        DB::table('booking_pets')->insert([...$session, 'grooming_estimate_min' => 90,
            'grooming_estimate_max' => 105, 'grooming_estimate_factors' => '["thick_coat"]']);
        DB::table('services')->insert(['service_id' => 1, 'base_price' => 650, 'duration_minutes' => 90]);
        DB::table('bookings')->insert([
            ['booking_date' => '2026-10-25', 'arrival_end_time' => '09:30:00', 'status' => 'archived',
                'dropped_off_at' => '2026-10-25 08:50:00', 'grooming_started_at' => '2026-10-25 09:00:00', 'grooming_finished_at' => '2026-10-25 11:18:00'],
            ['booking_date' => '2026-10-27', 'arrival_end_time' => '15:10:00', 'status' => 'waiting_to_arrive',
                'dropped_off_at' => null, 'grooming_started_at' => null, 'grooming_finished_at' => null],
        ]);
        DB::table('payments')->insert(['total_amount' => 650, 'paid_at' => '2026-10-25 11:20:00']);
        $bookings = DB::table('bookings')->get()->toArray();
        $payments = DB::table('payments')->get()->toArray();

        (require database_path('migrations/2026_10_26_000003_retire_grooming_duration_estimates.php'))->up();

        $this->assertDatabaseHas('booking_pets', $session);
        $this->assertEquals($session, (array) DB::table('booking_pets')->first());
        $this->assertEquals($bookings, DB::table('bookings')->get()->toArray());
        $this->assertEquals($payments, DB::table('payments')->get()->toArray());
        $this->assertEquals(['service_id' => 1, 'base_price' => 650], (array) DB::table('services')->first());
    }

    public function test_clean_install_migrates_without_the_retired_engine(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        } else {
            // The original core schema uses MySQL's ON UPDATE syntax. Exercise
            // the complete affected historical chain here; full install runs on MySQL.
            Schema::create('booking_pets', function (Blueprint $table) {
                $table->increments('booking_pet_id');
                $table->timestamp('grooming_start_time')->nullable();
                $table->timestamp('grooming_end_time')->nullable();
            });
            Schema::create('services', function (Blueprint $table) {
                $table->increments('service_id');
                $table->string('slug');
                $table->boolean('is_active')->default(true);
            });
            foreach (['2026_07_02_212126_add_duration_minutes_to_services_table.php',
                '2026_10_09_000001_add_grooming_estimates_to_booking_pets.php',
                '2026_10_16_000002_refresh_unfinished_grooming_estimates.php',
                '2026_10_26_000003_retire_grooming_duration_estimates.php'] as $file) {
                (require database_path('migrations/'.$file))->up();
            }
        }
        foreach (['grooming_estimate_min', 'grooming_estimate_max', 'grooming_estimate_factors'] as $column) {
            $this->assertFalse(Schema::hasColumn('booking_pets', $column));
        }
        $this->assertFalse(Schema::hasColumn('services', 'duration_minutes'));
        $this->assertTrue(Schema::hasColumns('booking_pets', ['grooming_preference', 'grooming_start_time', 'grooming_end_time']));
        if (DB::getDriverName() === 'mysql') $this->assertTrue(Schema::hasColumn('bookings', 'arrival_end_time'));
    }
}
