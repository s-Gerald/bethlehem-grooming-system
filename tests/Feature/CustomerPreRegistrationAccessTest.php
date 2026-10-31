<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPreRegistrationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('role')->default('customer');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id');
            $table->string('status');
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });

        DB::table('users')->insert([
            'user_id' => 22,
            'role' => 'customer',
        ]);
        $customer = User::query()->findOrFail(22);
        Sanctum::actingAs($customer, ['*']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_completed_and_terminal_visits_never_limit_future_pre_registration(): void
    {
        foreach (range(1, 12) as $number) {
            DB::table('bookings')->insert([
                'booking_reference' => "BAC-HISTORY-{$number}",
                'user_id' => 22,
                'status' => $number % 3 === 0 ? 'cancelled' : 'archived',
            ]);

            DB::table('clinic_appointments')->insert([
                'appointment_reference' => "CL-HISTORY-{$number}",
                'user_id' => 22,
                'status' => $number % 3 === 0
                    ? 'cancelled'
                    : ($number % 2 === 0 ? 'no_show' : 'completed'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/pre-registration/access')
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('services.grooming.allowed', true)
            ->assertJsonPath('services.clinic.allowed', true);
    }

    public function test_ongoing_grooming_only_blocks_grooming(): void
    {
        DB::table('bookings')->insert([
            'booking_reference' => 'BAC-ONGOING-1',
            'user_id' => 22,
            'status' => 'for_payment',
        ]);

        $this->getJson('/api/pre-registration/access')
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('services.grooming.allowed', false)
            ->assertJsonPath('services.clinic.allowed', true)
            ->assertJsonPath('services.grooming.ongoing.reference', 'BAC-ONGOING-1');
    }

    public function test_ongoing_clinic_only_blocks_clinic(): void
    {
        DB::table('bookings')->insert([
            'booking_reference' => 'BAC-COMPLETE-1',
            'user_id' => 22,
            'status' => 'archived',
        ]);
        DB::table('clinic_appointments')->insert([
            'appointment_reference' => 'CL-ONGOING-1',
            'user_id' => 22,
            'status' => 'in_consultation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/pre-registration/access')
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('services.clinic.allowed', false)
            ->assertJsonPath('services.grooming.allowed', true)
            ->assertJsonPath('services.clinic.ongoing.reference', 'CL-ONGOING-1');
    }

    public function test_both_active_services_block_entry_and_return_both_references(): void
    {
        DB::table('bookings')->insert(['booking_reference' => 'OR-BOTH', 'user_id' => 22, 'status' => 'checked_in']);
        DB::table('clinic_appointments')->insert(['appointment_reference' => 'CL-BOTH', 'user_id' => 22, 'status' => 'in_consultation']);
        $this->getJson('/api/pre-registration/access')->assertOk()
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('services.grooming.allowed', false)
            ->assertJsonPath('services.clinic.allowed', false)
            ->assertJsonPath('services.grooming.ongoing.reference', 'OR-BOTH')
            ->assertJsonPath('services.clinic.ongoing.reference', 'CL-BOTH');
    }

    public function test_all_terminal_statuses_release_both_services_and_other_accounts_do_not_block(): void
    {
        foreach (['completed', 'cancelled', 'expired', 'no_show', 'archived'] as $status) {
            DB::table('bookings')->insert(['booking_reference' => "OR-{$status}", 'user_id' => 22, 'status' => $status]);
            DB::table('clinic_appointments')->insert(['appointment_reference' => "CL-{$status}", 'user_id' => 22, 'status' => $status]);
        }
        DB::table('bookings')->insert(['booking_reference' => 'OR-OTHER', 'user_id' => 23, 'status' => 'checked_in']);
        DB::table('clinic_appointments')->insert(['appointment_reference' => 'CL-OTHER', 'user_id' => 23, 'status' => 'in_consultation']);
        $this->getJson('/api/pre-registration/access')->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('services.grooming.allowed', true)
            ->assertJsonPath('services.grooming.ongoing', null)
            ->assertJsonPath('services.clinic.allowed', true)
            ->assertJsonPath('services.clinic.ongoing', null);
    }
}
