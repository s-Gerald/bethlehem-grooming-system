<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerPetSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('role')->default('customer');
            $table->string('customer_tier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id');
            $table->string('status');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->string('pet_name');
            $table->string('species');
            $table->string('breed')->nullable();
            $table->boolean('is_archived')->default(false);
        });

        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Staff',
                'last_name' => 'User',
                'email' => 'staff@example.test',
                'phone' => null,
                'role' => 'staff',
                'email_verified_at' => now(),
            ],
            [
                'user_id' => 10,
                'first_name' => 'Andrew',
                'last_name' => 'Hermosa',
                'email' => 'andrew@example.test',
                'phone' => '09171234567',
                'role' => 'customer',
                'email_verified_at' => now(),
            ],
            [
                'user_id' => 20,
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'email' => 'juan@example.test',
                'phone' => '09179876543',
                'role' => 'customer',
                'email_verified_at' => now(),
            ],
            [
                'user_id' => 30,
                'first_name' => 'Max',
                'last_name' => 'Hernandez',
                'email' => 'max@example.test',
                'phone' => null,
                'role' => 'customer',
                'email_verified_at' => now(),
            ],
            [
                'user_id' => 40,
                'first_name' => 'Pending',
                'last_name' => 'Signup',
                'email' => 'pending@example.test',
                'phone' => '09170000000',
                'role' => 'customer',
                'email_verified_at' => null,
            ],
        ]);

        DB::table('pets')->insert([
            [
                'pet_id' => 101,
                'user_id' => 10,
                'pet_name' => 'Max',
                'species' => 'Dog',
                'breed' => 'Shih Tzu',
            ],
            [
                'pet_id' => 102,
                'user_id' => 20,
                'pet_name' => 'Max',
                'species' => 'Dog',
                'breed' => 'Golden Retriever',
            ],
            [
                'pet_id' => 103,
                'user_id' => 30,
                'pet_name' => 'Buddy',
                'species' => 'Dog',
                'breed' => 'Beagle',
            ],
            [
                'pet_id' => 104,
                'user_id' => 40,
                'pet_name' => 'Ghost',
                'species' => 'Dog',
                'breed' => 'Mixed',
            ],
        ]);

        Sanctum::actingAs(User::query()->findOrFail(1), ['*']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_search_returns_customer_and_pet_matches_as_separate_results(): void
    {
        $this->getJson('/api/admin/customers?status=active&search=Max')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('customers.0.fullName', 'Max Hernandez')
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('petTotal', 2)
            ->assertJsonCount(2, 'pets')
            ->assertJsonPath('pets.0.petName', 'Max')
            ->assertJsonPath('pets.0.species', 'Dog')
            ->assertJsonPath('pets.0.breed', 'Shih Tzu')
            ->assertJsonPath('pets.0.ownerId', 10)
            ->assertJsonPath('pets.0.ownerName', 'Andrew Hermosa')
            ->assertJsonPath('pets.0.ownerPhone', '09171234567')
            ->assertJsonPath('pets.0.ownerEmail', 'andrew@example.test')
            ->assertJsonPath('pets.1.petName', 'Max')
            ->assertJsonPath('pets.1.breed', 'Golden Retriever')
            ->assertJsonPath('pets.1.ownerId', 20)
            ->assertJsonPath('pets.1.ownerName', 'Juan Dela Cruz')
            ->assertJsonPath('pets.1.ownerPhone', '09179876543')
            ->assertJsonPath('pets.1.ownerEmail', 'juan@example.test');
    }

    public function test_pet_results_are_empty_until_a_search_is_entered(): void
    {
        $this->getJson('/api/admin/customers?status=active')
            ->assertOk()
            ->assertJsonPath('petTotal', 0)
            ->assertJsonCount(0, 'pets');
    }

    public function test_customer_counts_keep_exact_tiers_without_per_customer_queries(): void
    {
        DB::table('bookings')->insert(array_map(fn ($status) => ['user_id' => 10, 'status' => $status],
            ['cancelled', 'waiting_to_arrive', 'expired', 'no_show', 'checked_in']));
        DB::table('bookings')->insert(['user_id' => 20, 'status' => 'waiting_to_arrive']);
        DB::enableQueryLog();
        $this->getJson('/api/admin/customers?status=active')->assertOk()
            ->assertJsonPath('customers.0.bookingCount', 4)->assertJsonPath('customers.0.tier', 'returning')
            ->assertJsonPath('customers.1.bookingCount', 1)->assertJsonPath('customers.1.tier', 'new');
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($id = 100; $id < 110; $id++) DB::table('users')->insert([
            'user_id' => $id, 'first_name' => 'Test', 'last_name' => (string) $id, 'role' => 'customer', 'email_verified_at' => now(),
        ]);
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson('/api/admin/customers?status=active')->assertOk()->assertJsonCount(13, 'customers');
        $large = count(DB::getQueryLog()); DB::disableQueryLog();
        fwrite(STDOUT, "\nCustomer query growth: 3 customers={$small}, 13 customers={$large}\n");
        $this->assertSame($small, $large, 'Adding ten customers must not add twenty booking queries.');
        $this->getJson('/api/admin/customers?status=active&tier=returning&search=Max')->assertOk()
            ->assertJsonCount(0, 'customers')->assertJsonCount(1, 'pets')->assertJsonPath('pets.0.ownerId', 10);
        $this->getJson('/api/admin/customers?status=active&tier=new&search=Max')->assertOk()
            ->assertJsonCount(1, 'customers')->assertJsonCount(1, 'pets')->assertJsonPath('pets.0.ownerId', 20);
    }

    public function test_unverified_legacy_customer_is_hidden_from_admin_customer_operations(): void
    {
        $this->getJson('/api/admin/customers?status=active&search=Pending')
            ->assertOk()
            ->assertJsonCount(0, 'customers');

        $this->getJson('/api/admin/customers?status=active&search=Ghost')
            ->assertOk()
            ->assertJsonCount(0, 'pets');

        $this->getJson('/api/admin/customers/40')
            ->assertNotFound()
            ->assertJsonPath('message', 'Customer not found.');
    }
}
