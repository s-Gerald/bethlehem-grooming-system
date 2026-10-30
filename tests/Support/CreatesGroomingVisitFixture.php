<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesGroomingVisitFixture
{
    use CreatesPetGroomers;

    private function createGroomingVisitFixture(): void
    {
        $this->createPetGroomers();
        Schema::create('users', function (Blueprint $t) {
            $t->increments('user_id');
            $t->string('first_name');
            $t->string('last_name');
        });
        Schema::create('clinic_settings', function (Blueprint $t) {
            $t->id();
            $t->integer('groomers_on_duty')->default(2);
        });
        DB::table('clinic_settings')->insert(['id' => 1]);
        Schema::create('bookings', function (Blueprint $t) {
            $t->increments('booking_id');
            $t->string('booking_reference')->default('TEST');
            $t->unsignedInteger('user_id')->nullable();
            $t->date('booking_date')->nullable();
            $t->string('status')->default('checked_in');
            $t->boolean('paid')->default(false);
            $t->integer('queue_number')->nullable();
            $t->integer('number_of_pets')->default(2);
            $t->timestamp('dropped_off_at')->nullable();
            $t->timestamp('grooming_started_at')->nullable();
            $t->timestamp('grooming_finished_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->integer('cancel_count')->default(0);
            $t->decimal('total_amount', 10, 2)->default(1500);
        });
        Schema::create('pets', function (Blueprint $t) {
            $t->json('customer_locked_fields')->nullable();
            $t->increments('pet_id');
            $t->string('pet_name');
            $t->string('species')->default('Dog');
            $t->string('size')->default('large');
        });
        Schema::create('booking_pets', function (Blueprint $t) {
            $t->increments('booking_pet_id');
            $t->unsignedInteger('booking_id');
            $t->unsignedInteger('pet_id');
            $t->unsignedInteger('groomer_id')->nullable();
            $t->string('registered_size')->default('large');
            $t->string('confirmed_size')->default('large');
            $t->string('grooming_state')->default('not_started');
            $t->timestamp('grooming_start_time')->nullable();
            $t->timestamp('grooming_end_time')->nullable();
            $t->date('pet_queue_date')->nullable();
            $t->integer('pet_queue_number')->nullable();
        });
        Schema::create('services', function (Blueprint $t) {
            $t->increments('service_id');
            $t->string('slug');
            $t->string('service_name');
        });
        Schema::create('booking_services', function (Blueprint $t) {
            $t->increments('booking_service_id');
            $t->unsignedInteger('booking_id');
            $t->unsignedInteger('booking_pet_id');
            $t->unsignedInteger('service_id');
            $t->decimal('price_at_booking', 10, 2);
            $t->decimal('price_min_at_booking', 10, 2)->nullable();
            $t->decimal('price_max_at_booking', 10, 2)->nullable();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->increments('payment_id');
            $t->unsignedInteger('booking_id');
            $t->string('payment_status')->default('paid');
            $t->decimal('total_amount', 10, 2)->default(1500);
            $t->decimal('amount_tendered', 10, 2)->default(1500);
            $t->decimal('change_amount', 10, 2)->default(0);
            $t->string('payment_method')->default('cash');
            $t->text('notes')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        foreach (['notifications', 'customer_notifications'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('booking_id');
                $t->unsignedInteger('user_id')->nullable();
                $t->string('type');
                $t->text('message');
                $t->boolean('is_read');
                $t->timestamp('created_at');
            });
        }
        (require database_path('migrations/2026_10_16_000001_add_grooming_cancellation_audit.php'))->up();
        (require database_path('migrations/2026_10_16_000005_create_grooming_consents.php'))->up();
        DB::table('users')->insert(['user_id' => 1, 'first_name' => 'Gerald', 'last_name' => 'Senining']);
        DB::table('bookings')->insert(['booking_id' => 1, 'user_id' => 1, 'queue_number' => 3, 'dropped_off_at' => now()]);
        DB::table('services')->insert(['service_id' => 1, 'slug' => 'regular_dog_grooming', 'service_name' => 'Regular Dog Grooming']);
        foreach ([1 => 'Hopper', 2 => 'Mark'] as $id => $name) {
            DB::table('pets')->insert(['pet_id' => $id, 'pet_name' => $name]);
            DB::table('booking_pets')->insert(['booking_pet_id' => $id, 'booking_id' => 1, 'pet_id' => $id,
                'pet_queue_date' => now()->toDateString(), 'pet_queue_number' => $id]);
            DB::table('booking_services')->insert(['booking_service_id' => $id, 'booking_id' => 1,
                'booking_pet_id' => $id, 'service_id' => 1, 'price_at_booking' => $id === 1 ? 850 : 650]);
        }
    }

}
