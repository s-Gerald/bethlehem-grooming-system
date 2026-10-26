<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUnregisteredCustomerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('role')->default('customer');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
        });

        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 5)->nullable();
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('gender')->nullable();
            $table->date('birthdate')->nullable();
            $table->boolean('is_neutered')->default(false);
            $table->date('neutered_date')->nullable();
            $table->boolean('is_deceased')->default(false);
            $table->date('deceased_date')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('fur_type')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        DB::table('users')->insert([
            [
                'user_id' => 1,
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => 'admin@example.test',
                'phone' => null,
                'role' => 'admin',
            ],
            [
                'user_id' => 2,
                'first_name' => 'Staff',
                'last_name' => 'User',
                'email' => 'staff@example.test',
                'phone' => null,
                'role' => 'staff',
            ],
            [
                'user_id' => 3,
                'first_name' => 'Registered',
                'last_name' => 'Customer',
                'email' => 'registered@example.test',
                'phone' => '09170000003',
                'role' => 'customer',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pets');
        Schema::dropIfExists('unregistered_customers');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_admin_can_create_and_list_an_unregistered_customer(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(1), ['*']);

        $this->postJson('/api/admin/customers/unregistered', [
            'first_name' => '  Maria  ',
            'last_name' => ' Santos ',
            'middle_name' => 'a.',
            'phone' => '+63 917 123 4567',
            'email' => 'MARIA@example.test',
        ])
            ->assertCreated()
            ->assertJsonPath('customer.recordType', 'unregistered')
            ->assertJsonPath('customer.status', 'unregistered')
            ->assertJsonPath('customer.fullName', 'Maria A. Santos')
            ->assertJsonPath('customer.phone', '09171234567')
            ->assertJsonPath('customer.email', 'maria@example.test');

        $this->assertDatabaseHas('unregistered_customers', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'middle_name' => 'A',
            'phone' => '09171234567',
            'email' => 'maria@example.test',
            'created_by_user_id' => 1,
        ]);

        $this->getJson('/api/admin/customers?status=unregistered')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('petTotal', 0)
            ->assertJsonPath('customers.0.status', 'unregistered')
            ->assertJsonPath('customers.0.fullName', 'Maria A. Santos');

        $this->getJson('/api/admin/customers/unregistered/1')
            ->assertOk()
            ->assertJsonPath('customer.middleName', 'A')
            ->assertJsonPath('customer.bookingCount', 0);
    }

    public function test_unregistered_tab_has_an_empty_and_searchable_response(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(2), ['*']);

        $this->getJson('/api/admin/customers?status=unregistered')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'customers')
            ->assertJsonCount(0, 'pets');

        DB::table('unregistered_customers')->insert([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'phone' => '09175555555',
            'email' => 'juan@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/admin/customers?status=unregistered&search=Dela')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('customers.0.fullName', 'Juan Dela Cruz');

        $this->getJson('/api/admin/customers?status=unregistered&search=missing')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_staff_can_view_and_create_unregistered_customers(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(2), ['*']);

        $this->getJson('/api/admin/customers?status=unregistered')->assertOk();

        $this->postJson('/api/admin/customers/unregistered', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone' => '09171234567',
        ])
            ->assertCreated()
            ->assertJsonPath('customer.status', 'unregistered');

        $this->assertDatabaseCount('unregistered_customers', 1);
    }

    public function test_creation_validates_walk_in_owner_fields_and_rejects_registered_duplicates(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(1), ['*']);

        $this->postJson('/api/admin/customers/unregistered', [
            'first_name' => '',
            'last_name' => '',
            'phone' => '1234',
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'phone', 'email']);

        $this->postJson('/api/admin/customers/unregistered', [
            'first_name' => 'Registered',
            'last_name' => 'Again',
            'phone' => '09170000003',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('unregistered_customers', 0);
    }

    public function test_similar_name_requires_confirmation_but_a_different_phone_is_allowed(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(1), ['*']);

        $payload = [
            'first_name' => 'Registered',
            'last_name' => 'Customer',
            'phone' => '09170000099',
        ];

        $this->postJson('/api/admin/customers/unregistered', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'similar_customer_name')
            ->assertJsonPath('similarCustomers.0.phone', '09170000003');

        $this->postJson('/api/admin/customers/unregistered', [
            ...$payload,
            'confirm_similar_name' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('unregistered_customers', [
            'phone' => '09170000099',
        ]);
    }

    public function test_walk_in_new_owner_check_rejects_duplicate_contacts_and_returns_similar_names(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(2), ['*']);

        $this->postJson('/api/admin/walk-in/customers/validate-new-owner', [
            'first_name' => 'Another',
            'last_name' => 'Owner',
            'phone' => '09170000003',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        DB::table('unregistered_customers')->insert([
            'first_name' => 'Guest',
            'last_name' => 'Owner',
            'phone' => '09170000004',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/admin/walk-in/customers/validate-new-owner', [
            'first_name' => 'Another',
            'last_name' => 'Owner',
            'phone' => '09170000004',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $similarPayload = [
            'first_name' => 'Registered',
            'last_name' => 'Customer',
            'phone' => '09170000099',
        ];

        $this->postJson('/api/admin/walk-in/customers/validate-new-owner', $similarPayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'similar_customer_name')
            ->assertJsonPath('similarCustomers.0.status', 'Active');

        $this->postJson('/api/admin/walk-in/customers/validate-new-owner', [
            ...$similarPayload,
            'confirm_similar_name' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('unregistered_customers', 1);
    }

    public function test_unregistered_customer_can_add_a_pet_and_be_archived(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(2), ['*']);

        $customerId = DB::table('unregistered_customers')->insertGetId([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone' => '09175550000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/admin/customer-pets/unregistered/{$customerId}", [
            'pet_name' => 'mochi',
            'species' => 'Cat',
            'breed' => 'Persian',
            'fur_type' => 'Long Dense Coat',
            'weight' => 4,
            'size' => 'small',
        ])->assertCreated()
            ->assertJsonPath('pet.unregistered_customer_id', $customerId)
            ->assertJsonPath('pet.pet_name', 'Mochi');

        $this->getJson("/api/admin/customers/unregistered/{$customerId}")
            ->assertOk()
            ->assertJsonPath('customer.petCount', 1)
            ->assertJsonPath('customer.pets.0.petName', 'Mochi');

        $this->postJson("/api/admin/customers/unregistered/{$customerId}/archive")
            ->assertOk();

        $this->getJson('/api/admin/customers?status=unregistered')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_walk_in_search_returns_active_and_unregistered_customers_with_pets(): void
    {
        Sanctum::actingAs(User::query()->findOrFail(2), ['*']);

        DB::table('users')->insert([
            'user_id' => 10,
            'first_name' => 'Walkin',
            'last_name' => 'Active',
            'email' => 'active-walkin@example.test',
            'phone' => '09170000010',
            'role' => 'customer',
            'is_active' => true,
            'is_archived' => false,
        ]);
        $unregisteredId = DB::table('unregistered_customers')->insertGetId([
            'first_name' => 'Walkin',
            'last_name' => 'Guest',
            'phone' => '09170000011',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pets')->insert([
            [
                'user_id' => 10,
                'unregistered_customer_id' => null,
                'pet_name' => 'Buddy',
                'species' => 'Dog',
                'is_archived' => false,
            ],
            [
                'user_id' => null,
                'unregistered_customer_id' => $unregisteredId,
                'pet_name' => 'Pepper',
                'species' => 'Cat',
                'is_archived' => false,
            ],
        ]);

        $this->getJson('/api/admin/walk-in/customers?q=Walkin')
            ->assertOk()
            ->assertJsonCount(2, 'customers')
            ->assertJsonPath('customers.0.status', 'active')
            ->assertJsonPath('customers.1.status', 'unregistered')
            ->assertJsonPath('customers.1.pets.0.petName', 'Pepper');

        $this->getJson('/api/admin/walk-in/customers?q=Pepper')
            ->assertOk()
            ->assertJsonCount(0, 'customers')
            ->assertJsonCount(1, 'pets')
            ->assertJsonPath('pets.0.petName', 'Pepper')
            ->assertJsonPath('pets.0.species', 'Cat')
            ->assertJsonPath('pets.0.ownerId', $unregisteredId)
            ->assertJsonPath('pets.0.ownerName', 'Walkin Guest')
            ->assertJsonPath('pets.0.ownerRecordType', 'unregistered')
            ->assertJsonPath('pets.0.ownerStatus', 'Unregistered');

        $this->getJson('/api/admin/walk-in/customers?q=Buddy')
            ->assertOk()
            ->assertJsonCount(1, 'pets')
            ->assertJsonPath('pets.0.ownerId', 10)
            ->assertJsonPath('pets.0.ownerName', 'Walkin Active')
            ->assertJsonPath('pets.0.ownerRecordType', 'registered')
            ->assertJsonPath('pets.0.ownerStatus', 'Active');

        DB::table('users')->where('user_id', 10)->update(['phone' => null]);
        $this->getJson('/api/admin/walk-in/customers?q=Walkin')
            ->assertOk()->assertJsonPath('customers.0.phone', null)
            ->assertJsonPath('customers.0.pets.0.petName', 'Buddy');
    }
}
