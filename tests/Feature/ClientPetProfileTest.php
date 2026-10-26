<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPetProfileTest extends TestCase
{
    public function test_customer_admin_and_staff_create_and_edit_enforce_conditional_fur(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('role');
        });
        DB::table('users')->insert(['user_id' => 10, 'role' => 'customer']);
        try {
            foreach (['customer', 'admin', 'staff'] as $role) {
                $this->authenticateUser($role === 'customer' ? 10 : 20, $role);
                $createUrl = $role === 'customer' ? '/api/pets' : '/api/admin/customer-pets/registered/10';
                foreach ([['Dog', ' golden   retriever ', 'Wavy Coat'], ['Cat', 'Bengal', 'Longhair Coat'], ['Dog', 'Beagle', 'Smooth Short Coat']] as $index => [$species, $breed, $coat]) {
                    $payload = ['pet_name' => "$role named $index", 'species' => $species, 'breed' => $breed];
                    foreach ([[], ['fur_type' => null], ['fur_type' => 'Unsupported'], ['pet_id' => 999]] as $invalid) {
                        $this->postJson($createUrl, [...$payload, ...$invalid])->assertUnprocessable()->assertJsonValidationErrors('fur_type');
                    }
                    $id = $this->postJson($createUrl, [...$payload, 'fur_type' => $coat])->assertCreated()->json('pet.pet_id');
                    $editUrl = ($role === 'customer' ? '/api/pets/' : '/api/admin/pets/').$id;
                    $this->putJson($editUrl, $payload)->assertUnprocessable()->assertJsonPath('errors.fur_type.0', 'Select a fur type.');
                    $this->assertDatabaseHas('pets', ['pet_id' => $id, 'fur_type' => $coat]);
                    $this->putJson($editUrl, [...$payload, 'fur_type' => $coat])->assertOk()->assertJsonPath('pet.fur_type', $coat);
                    $this->putJson($editUrl, [...$payload, 'breed' => 'My custom breed', 'fur_type' => null])
                        ->assertOk()->assertJsonPath('pet.fur_type', null);
                }
                foreach (['My custom breed', 'Mixed Breed / Aspin', 'Unknown Breed'] as $index => $breed) {
                    $this->postJson($createUrl, ['pet_name' => "$role optional $index", 'species' => 'Dog', 'breed' => $breed])
                        ->assertCreated()->assertJsonPath('pet.fur_type', null);
                }
            }
        } finally {
            Schema::drop('users');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('gender')->nullable();
            $table->date('birthdate')->nullable();
            $table->boolean('is_neutered')->nullable();
            $table->date('neutered_date')->nullable();
            $table->boolean('is_deceased')->default(false);
            $table->date('deceased_date')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('fur_type')->nullable();
            $table->json('clinic_verified_fields')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
        });

        (require database_path('migrations/2026_10_11_000002_preserve_pet_weight_precision.php'))->up();

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->text('special_notes')->nullable();
            $table->dateTime('dropped_off_at')->nullable();
            $table->dateTime('grooming_started_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('service_name');
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id')->nullable();
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('pets');

        parent::tearDown();
    }

    public function test_customer_admin_and_staff_add_and_edit_share_exact_weight_rules(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role');
        });
        DB::table('users')->insert(['user_id' => 10, 'role' => 'customer']);
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true);
        foreach (['customer', 'admin', 'staff'] as $role) {
            $this->authenticateUser($role === 'customer' ? 10 : 20, $role);
            $createUrl = $role === 'customer' ? '/api/pets' : '/api/admin/customer-pets/registered/10';
            foreach ($cases['pets'] as $index => [$species, $weight, $size]) {
                $payload = ['pet_name' => $role.' pet '.$index, 'species' => $species,
                    'weight' => $weight, 'size' => $size === 'small' ? 'medium' : 'small'];
                $id = $this->postJson($createUrl, $payload)->assertCreated()->assertJsonPath('pet.size', $size)->json('pet.pet_id');
                $editUrl = ($role === 'customer' ? '/api/pets/' : '/api/admin/pets/').$id;
                $this->putJson($editUrl, $payload)->assertOk()->assertJsonPath('pet.size', $size);
                $this->assertDatabaseHas('pets', ['pet_id' => $id, 'weight' => $weight, 'size' => $size]);
            }
            foreach ($cases['invalid_weights'] as $index => $weight) {
                $this->postJson($createUrl, ['pet_name' => 'Invalid '.$index, 'species' => 'Dog', 'weight' => $weight])
                    ->assertUnprocessable()->assertJsonValidationErrors('weight');
                $this->putJson($editUrl, [...$payload, 'weight' => $weight])
                    ->assertUnprocessable()->assertJsonValidationErrors('weight');
                $this->assertDatabaseHas('pets', ['pet_id' => $id, 'weight' => $payload['weight'], 'size' => $size]);
            }
            $unknown = ['pet_name' => $role.' unknown', 'species' => 'Dog', 'weight' => null, 'size' => 'large'];
            $id = $this->postJson($createUrl, $unknown)->assertCreated()->assertJsonPath('pet.size', 'large')->json('pet.pet_id');
            $this->putJson(($role === 'customer' ? '/api/pets/' : '/api/admin/pets/').$id, $unknown)
                ->assertOk()->assertJsonPath('pet.size', 'large');
            $editUrl = ($role === 'customer' ? '/api/pets/' : '/api/admin/pets/').$id;
            $this->putJson($editUrl, [...$unknown, 'weight' => 4.25])
                ->assertOk()->assertJsonPath('pet.size', 'small');
            $this->putJson($editUrl, $unknown)->assertOk()
                ->assertJsonPath('pet.weight', null)->assertJsonPath('pet.size', 'large');
        }
        Schema::drop('users');
    }

    public function test_widening_weight_precision_preserves_existing_pet_records(): void
    {
        $migration = require database_path('migrations/2026_10_11_000002_preserve_pet_weight_precision.php');
        $migration->down();
        DB::table('pets')->insert([
            ['pet_id' => 1, 'user_id' => 10, 'pet_name' => 'Historical cat', 'species' => 'Cat', 'weight' => 4.99, 'size' => 'medium'],
            ['pet_id' => 2, 'user_id' => 10, 'pet_name' => 'Historical dog', 'species' => 'Dog', 'weight' => 155.6, 'size' => 'large'],
            ['pet_id' => 3, 'user_id' => 10, 'pet_name' => 'Unknown weight', 'species' => 'Dog', 'weight' => null, 'size' => 'small'],
        ]);
        $before = DB::table('pets')->orderBy('pet_id')->get()->all();
        $migration->up();
        $this->assertEquals($before, DB::table('pets')->orderBy('pet_id')->get()->all());
    }

    public function test_pet_profile_requires_authentication(): void
    {
        $this->getJson('/api/pets/101')->assertUnauthorized();
    }

    public function test_customer_can_open_only_a_pet_they_own(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'breed' => 'Persian',
            'gender' => 'female',
            'birthdate' => '2022-03-14',
            'is_neutered' => true,
            'weight' => 4.25,
            'color' => 'White',
            'size' => 'small',
            'fur_type' => 'long',
            'medical_conditions' => 'Sensitive skin',
            'is_archived' => false,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 202,
            'user_id' => 20,
            'pet_name' => 'Not My Pet',
            'species' => 'dog',
        ]);

        $this->getJson('/api/pets/101')
            ->assertOk()
            ->assertJsonPath('pet.pet_id', 101)
            ->assertJsonPath('pet.pet_name', 'Mochi')
            ->assertJsonPath('pet.medical_conditions', 'Sensitive skin');

        $this->getJson('/api/pets/202')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');
    }

    public function test_customer_can_add_gender_birthdate_and_connected_pet_fields(): void
    {
        $this->authenticateCustomer(10);

        $this->postJson('/api/pets', [
            'pet_name' => 'bruno',
            'species' => 'Dog',
            'breed' => 'Beagle',
            'gender' => 'male',
            'birthdate' => '2023-05-12',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 8.5,
        ])
            ->assertCreated()
            ->assertJsonPath('pet.pet_name', 'Bruno')
            ->assertJsonPath('pet.gender', 'male')
            ->assertJsonPath('pet.birthdate', '2023-05-12')
            ->assertJsonPath('pet.fur_type', 'Smooth Short Coat')
            ->assertJsonPath('pet.size', 'small');

        $this->assertDatabaseHas('pets', [
            'user_id' => 10,
            'pet_name' => 'Bruno',
            'gender' => 'male',
            'birthdate' => '2023-05-12',
            'size' => 'small',
        ]);
    }

    public function test_customer_cannot_add_a_case_insensitive_duplicate_of_an_archived_pet_name(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'Cat',
            'is_archived' => true,
        ]);

        $this->postJson('/api/pets', [
            'pet_name' => '  mochi  ',
            'species' => 'Cat',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pet_name'])
            ->assertJsonPath('errors.pet_name.0', 'You already have a pet with this name.');

        $this->assertDatabaseCount('pets', 1);
    }

    public function test_customer_cannot_rename_a_pet_to_a_sibling_pets_name(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'Cat'],
            ['pet_id' => 102, 'user_id' => 10, 'pet_name' => 'Bruno', 'species' => 'Dog'],
        ]);

        $this->putJson('/api/pets/102', [
            'pet_name' => 'MOCHI',
            'species' => 'Dog',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pet_name']);

        $this->assertDatabaseHas('pets', [
            'pet_id' => 102,
            'pet_name' => 'Bruno',
        ]);
    }

    public function test_different_customers_can_use_the_same_pet_name(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 202,
            'user_id' => 20,
            'pet_name' => 'Mochi',
            'species' => 'Cat',
        ]);

        $this->postJson('/api/pets', [
            'pet_name' => 'Mochi',
            'species' => 'Cat',
        ])->assertCreated();

        $this->assertDatabaseHas('pets', [
            'user_id' => 10,
            'pet_name' => 'Mochi',
        ]);
    }

    public function test_customer_can_edit_gender_birthdate_and_connected_pet_fields(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'breed' => 'Beagle',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 8.5,
            'size' => 'small',
        ]);

        $this->putJson('/api/pets/101', [
            'pet_name' => 'mochi',
            'species' => 'Cat',
            'breed' => 'Persian',
            'gender' => 'female',
            'birthdate' => '2022-03-14',
            'fur_type' => 'Long Dense Coat',
            'weight' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('pet.pet_name', 'Mochi')
            ->assertJsonPath('pet.gender', 'female')
            ->assertJsonPath('pet.birthdate', '2022-03-14')
            ->assertJsonPath('pet.fur_type', 'Long Dense Coat')
            ->assertJsonPath('pet.size', 'medium');

        $this->assertDatabaseHas('pets', [
            'pet_id' => 101,
            'gender' => 'female',
            'birthdate' => '2022-03-14',
            'size' => 'medium',
        ]);
    }

    public function test_staff_pet_edit_uses_the_customer_connected_field_rules(): void
    {
        $this->authenticateUser(20, 'staff');

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'breed' => 'Beagle',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 8.5,
            'size' => 'small',
        ]);

        $this->putJson('/api/admin/pets/101', [
            'pet_name' => 'mochi',
            'species' => 'Cat',
            'breed' => 'Persian',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 12,
            'size' => 'large',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fur_type', 'size']);

        $this->assertDatabaseHas('pets', [
            'pet_id' => 101,
            'species' => 'Dog',
            'size' => 'small',
        ]);

        $this->putJson('/api/admin/pets/101', [
            'pet_name' => 'Mochi',
            'species' => 'Cat',
            'breed' => 'Persian',
            'gender' => 'female',
            'birthdate' => '2022-03-14',
            'fur_type' => 'Long Dense Coat',
            'weight' => 6,
            'is_neutered' => true,
            'neutered_date' => '2023-01-10',
        ])
            ->assertOk()
            ->assertJsonPath('pet.pet_name', 'Mochi')
            ->assertJsonPath('pet.fur_type', 'Long Dense Coat')
            ->assertJsonPath('pet.size', 'medium')
            ->assertJsonPath('pet.is_neutered', 1)
            ->assertJsonPath('pet.clinic_verified_fields', [
                'breed',
                'fur_type',
                'weight',
                'size',
            ]);

        $this->assertDatabaseHas('pets', [
            'pet_id' => 101,
            'species' => 'Cat',
            'breed' => 'Persian',
            'fur_type' => 'Long Dense Coat',
            'weight' => 6,
            'size' => 'medium',
            'is_neutered' => true,
            'neutered_date' => '2023-01-10',
        ]);

        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 10,
            'pet_id' => 101,
            'type' => 'pet_information_updated',
            'message' => 'Information for Mochi has been updated by Bethlehem Animal Clinic.',
            'is_read' => false,
        ]);

        $this->authenticateCustomer(10);

        $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.type', 'pet_information_updated')
            ->assertJsonPath('notifications.0.pet_id', 101)
            ->assertJsonPath('notifications.0.pet_name', 'Mochi')
            ->assertJsonPath(
                'notifications.0.destination',
                './pet-details.html?pet_id=101&tab=overview',
            );
    }

    public function test_customer_edit_clears_only_changed_clinic_verifications(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'breed' => 'Beagle',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 8.5,
            'size' => 'small',
            'clinic_verified_fields' => json_encode([
                'breed',
                'fur_type',
                'weight',
                'size',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->putJson('/api/pets/101', [
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'breed' => 'Pug',
            'fur_type' => 'Smooth Short Coat',
            'weight' => 8.5,
            'size' => 'small',
        ])
            ->assertOk()
            ->assertJsonPath('pet.breed', 'Pug')
            ->assertJsonPath('pet.clinic_verified_fields', [
                'fur_type',
                'weight',
                'size',
            ]);

        $this->assertSame(
            ['fur_type', 'weight', 'size'],
            json_decode(
                DB::table('pets')
                    ->where('pet_id', 101)
                    ->value('clinic_verified_fields'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function test_weight_change_derives_size_and_clears_changed_verification(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Rigby',
            'species' => 'Dog',
            'weight' => 8,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size'], JSON_THROW_ON_ERROR),
        ]);

        $this->putJson('/api/pets/101', [
            'pet_name' => 'Rigby',
            'species' => 'Dog',
            'weight' => 12,
        ])->assertOk()
            ->assertJsonPath('pet.size', 'medium')
            ->assertJsonPath('pet.clinic_verified_fields', null);

        $this->putJson('/api/pets/101', [
            'pet_name' => 'Rigby',
            'species' => 'Dog',
            'weight' => 12,
            'size' => 'medium',
        ])->assertOk()
            ->assertJsonPath('pet.size', 'medium')
            ->assertJsonPath('pet.clinic_verified_fields', null);
    }

    public function test_pet_filtered_history_excludes_sibling_pets_and_their_services(): void
    {
        $this->authenticateCustomer(10);

        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'cat'],
            ['pet_id' => 102, 'user_id' => 10, 'pet_name' => 'Bruno', 'species' => 'dog'],
            ['pet_id' => 202, 'user_id' => 20, 'pet_name' => 'Not My Pet', 'species' => 'dog'],
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '8:00 AM - 10:00 AM',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 301,
                'booking_reference' => 'BAC-20260722-0001',
                'user_id' => 10,
                'window_id' => 1,
                'booking_date' => '2026-07-22',
                'number_of_pets' => 2,
                'status' => 'archived',
                'paid' => true,
                'dropped_off_at' => '2026-07-22 08:45:00',
                'grooming_started_at' => '2026-07-22 09:05:00',
                'grooming_finished_at' => '2026-07-22 10:15:00',
                'created_at' => '2026-07-20 09:00:00',
            ],
            [
                'booking_id' => 302,
                'booking_reference' => 'BAC-20260723-0002',
                'user_id' => 10,
                'window_id' => 1,
                'booking_date' => '2026-07-23',
                'number_of_pets' => 1,
                'status' => 'archived',
                'paid' => false,
                'dropped_off_at' => null,
                'grooming_started_at' => null,
                'grooming_finished_at' => null,
                'created_at' => '2026-07-20 10:00:00',
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 401,
                'booking_id' => 301,
                'pet_id' => 101,
                'grooming_start_time' => '2026-07-22 09:05:00',
                'grooming_end_time' => '2026-07-22 10:15:00',
            ],
            [
                'booking_pet_id' => 402,
                'booking_id' => 301,
                'pet_id' => 102,
                'grooming_start_time' => '2026-07-22 09:20:00',
                'grooming_end_time' => null,
            ],
            [
                'booking_pet_id' => 403,
                'booking_id' => 302,
                'pet_id' => 102,
                'grooming_start_time' => null,
                'grooming_end_time' => null,
            ],
        ]);
        DB::table('services')->insert([
            ['service_id' => 501, 'service_name' => 'Full Groom'],
            ['service_id' => 502, 'service_name' => 'Nail Trim'],
        ]);
        DB::table('booking_services')->insert([
            [
                'booking_service_id' => 601,
                'booking_id' => 301,
                'booking_pet_id' => 401,
                'service_id' => 501,
                'price_at_booking' => 800,
            ],
            [
                'booking_service_id' => 602,
                'booking_id' => 301,
                'booking_pet_id' => 402,
                'service_id' => 502,
                'price_at_booking' => 150,
            ],
        ]);

        $this->getJson('/api/booking/history?pet_id=101')
            ->assertOk()
            ->assertJsonCount(0, 'bookings')
            ->assertJsonCount(1, 'history')
            ->assertJsonCount(1, 'history.0.pets')
            ->assertJsonPath('history.0.booking_id', 301)
            ->assertJsonPath('history.0.dropped_off_timestamp', '2026-07-22T08:45:00+08:00')
            ->assertJsonPath('history.0.grooming_started_timestamp', '2026-07-22T09:05:00+08:00')
            ->assertJsonPath('history.0.grooming_finished_timestamp', '2026-07-22T10:15:00+08:00')
            ->assertJsonPath('history.0.pets.0.pet_id', 101)
            ->assertJsonPath('history.0.pets.0.species', 'cat')
            ->assertJsonPath('history.0.pets.0.grooming_status', 'grooming_finished')
            ->assertJsonPath('history.0.pets.0.grooming_started_at', '9:05 AM')
            ->assertJsonPath('history.0.pets.0.grooming_finished_at', '10:15 AM')
            ->assertJsonPath('history.0.pets.0.grooming_started_timestamp', '2026-07-22T09:05:00+08:00')
            ->assertJsonPath('history.0.pets.0.grooming_finished_timestamp', '2026-07-22T10:15:00+08:00')
            ->assertJsonPath('history.0.pets.0.services.0.service_name', 'Full Groom')
            ->assertJsonMissing(['pet_id' => 102])
            ->assertJsonMissing(['service_name' => 'Nail Trim']);

        $this->getJson('/api/booking/history?pet_id=202')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');
    }

    private function authenticateCustomer(int $userId): void
    {
        $this->authenticateUser($userId, 'customer');
    }

    private function authenticateUser(int $userId, string $role): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'role' => $role,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }
}
