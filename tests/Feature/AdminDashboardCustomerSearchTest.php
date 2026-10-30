<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardCustomerSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
        });

        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('phone');
            $table->boolean('is_archived')->default(false);
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->json('customer_locked_fields')->nullable();
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
        });

        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Staff',
                'last_name' => 'User',
                'phone' => null,
                'role' => 'staff',
            ],
            [
                'user_id' => 10,
                'first_name' => 'Andrew',
                'last_name' => 'Hermosa',
                'phone' => '09171234567',
                'role' => 'customer',
            ],
            [
                'user_id' => 20,
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'phone' => '09179876543',
                'role' => 'customer',
            ],
            [
                'user_id' => 30,
                'first_name' => 'Max',
                'last_name' => 'Hernandez',
                'phone' => '09170000000',
                'role' => 'customer',
            ],
        ]);

        DB::table('unregistered_customers')->insert([
            [
                'id' => 100,
                'first_name' => 'Andrew',
                'last_name' => 'Hermosa',
                'middle_name' => null,
                'phone' => '09171111111',
                'is_archived' => false,
            ],
            [
                'id' => 200,
                'first_name' => 'Archived',
                'last_name' => 'Guest',
                'middle_name' => null,
                'phone' => '09172222222',
                'is_archived' => true,
            ],
        ]);

        DB::table('pets')->insert([
            [
                'pet_id' => 101,
                'user_id' => 10,
                'unregistered_customer_id' => null,
                'pet_name' => 'Max',
                'species' => 'Dog',
            ],
            [
                'pet_id' => 102,
                'user_id' => 20,
                'unregistered_customer_id' => null,
                'pet_name' => 'Max',
                'species' => 'Cat',
            ],
            [
                'pet_id' => 103,
                'user_id' => null,
                'unregistered_customer_id' => 100,
                'pet_name' => 'Max',
                'species' => 'Dog',
            ],
        ]);

        Sanctum::actingAs(User::query()->findOrFail(1), ['*']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pets');
        Schema::dropIfExists('unregistered_customers');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_staff_search_receives_separate_minimal_customer_and_pet_results(): void
    {
        $this->getJson('/api/admin/dashboard/search?q=Max')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.id', 30)
            ->assertJsonPath('customers.0.name', 'Max Hernandez')
            ->assertJsonPath('customers.0.phone', '09170000000')
            ->assertJsonMissingPath('customers.0.email')
            ->assertJsonCount(3, 'pets')
            ->assertJsonPath('pets.0.id', 101)
            ->assertJsonPath('pets.0.name', 'Max')
            ->assertJsonPath('pets.0.species', 'Dog')
            ->assertJsonPath('pets.0.ownerId', 10)
            ->assertJsonPath('pets.0.ownerName', 'Andrew Hermosa')
            ->assertJsonMissingPath('pets.0.breed')
            ->assertJsonPath('pets.1.id', 102)
            ->assertJsonPath('pets.1.species', 'Cat')
            ->assertJsonPath('pets.1.ownerName', 'Juan Dela Cruz')
            ->assertJsonPath('pets.2.id', 103)
            ->assertJsonPath('pets.2.ownerId', 100)
            ->assertJsonPath('pets.2.ownerName', 'Andrew Hermosa')
            ->assertJsonPath('pets.2.ownerRecordType', 'unregistered');
    }

    public function test_full_customer_name_search_is_supported(): void
    {
        $this->getJson('/api/admin/dashboard/search?q=Andrew%20Hermosa')
            ->assertOk()
            ->assertJsonCount(2, 'customers')
            ->assertJsonPath('customers.0.name', 'Andrew Hermosa')
            ->assertJsonPath('customers.0.recordType', 'registered')
            ->assertJsonPath('customers.0.status', 'Active')
            ->assertJsonPath('customers.1.name', 'Andrew Hermosa')
            ->assertJsonPath('customers.1.recordType', 'unregistered')
            ->assertJsonPath('customers.1.status', 'Unregistered')
            ->assertJsonCount(0, 'pets');
    }

    public function test_staff_can_find_an_owner_by_phone_number(): void
    {
        $this->getJson('/api/admin/dashboard/search?q=0917123')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.id', 10)
            ->assertJsonPath('customers.0.name', 'Andrew Hermosa')
            ->assertJsonPath('customers.0.phone', '09171234567')
            ->assertJsonCount(0, 'pets');
    }

    public function test_staff_can_find_an_unregistered_owner_by_phone_but_not_an_archived_one(): void
    {
        $this->getJson('/api/admin/dashboard/search?q=0917111')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.id', 100)
            ->assertJsonPath('customers.0.recordType', 'unregistered');

        $this->getJson('/api/admin/dashboard/search?q=0917222')
            ->assertOk()
            ->assertJsonCount(0, 'customers');
    }
}
