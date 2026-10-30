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
    use \Tests\Support\CreatesRegistrationAvailability;

    public function test_color_validation_normalization_and_legacy_preservation_for_customer_admin_and_staff(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('role');
        });
        DB::table('users')->insert(['user_id' => 10, 'role' => 'customer']);
        try {
            foreach (['customer', 'admin', 'staff'] as $role) {
                $this->authenticateUser($role === 'customer' ? 10 : 20, $role);
                $url = $role === 'customer' ? '/api/pets' : '/api/admin/customer-pets/registered/10';
                $payload = ['pet_name' => "$role color", 'species' => 'Dog', 'medical_conditions' => 'Legacy heart condition; shampoo allergy'];
                foreach ([123, '123', 'Brown123', '456White', '12.5', '---', '& /', 'Brown１２', str_repeat('x', 51)] as $invalid) {
                    $this->postJson($url, [...$payload, 'color' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('color');
                }
                $id = $this->postJson($url, [...$payload, 'color' => '  White   and  Brown  '])->assertCreated()
                    ->assertJsonPath('pet.color', 'White and Brown')->json('pet.pet_id');
                $edit = ($role === 'customer' ? '/api/pets/' : '/api/admin/pets/').$id;
                if ($role === 'customer') {
                    $this->putJson($edit, $payload)->assertOk()->assertJsonPath('pet.color', 'White and Brown');
                    $this->putJson($edit, [...$payload, 'color' => 'Brown/White'])->assertUnprocessable()->assertJsonValidationErrors('color');
                    DB::table('pets')->where('pet_id', $id)->update(['color' => 'Brown123']);
                    $this->putJson($edit, [...$payload, 'color' => 'Brown123'])->assertOk()->assertJsonPath('pet.color', 'Brown123');
                    $this->putJson($edit, [...$payload, 'color' => null, 'medical_conditions' => null])
                        ->assertUnprocessable()->assertJsonValidationErrors(['color', 'medical_conditions']);
                    continue;
                }
                $this->putJson($edit, ['pet_name' => "$role renamed", 'species' => 'Dog'])->assertOk()
                    ->assertJsonPath('pet.color', 'White and Brown')
                    ->assertJsonPath('pet.medical_conditions', 'Legacy heart condition; shampoo allergy');
                $this->putJson($edit, [...$payload, 'color' => 'Brown/White'])->assertOk()->assertJsonPath('pet.color', 'Brown/White');
                DB::table('pets')->where('pet_id', $id)->update(['color' => 'Brown123']);
                $this->putJson($edit, [...$payload, 'color' => 'Brown123'])->assertOk()->assertJsonPath('pet.color', 'Brown123');
                $this->putJson($edit, [...$payload, 'color' => 'Brown456'])->assertUnprocessable()->assertJsonValidationErrors('color');
                $this->assertDatabaseHas('pets', ['pet_id' => $id, 'color' => 'Brown123', 'medical_conditions' => $payload['medical_conditions']]);
                $this->putJson($edit, [...$payload, 'color' => null, 'medical_conditions' => null])->assertOk()
                    ->assertJsonPath('pet.color', null)->assertJsonPath('pet.medical_conditions', null);
                $this->putJson($edit, [...$payload, 'medical_conditions' => str_repeat('x', 1001)])->assertUnprocessable()->assertJsonValidationErrors('medical_conditions');
            }
        } finally {
            Schema::drop('users');
        }
    }
    public function test_customer_admin_and_staff_allow_optional_fur_and_validate_explicit_choices(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role');
        });
        DB::table('users')->insert(['user_id' => 10, 'role' => 'customer']);
        try {
            foreach (['customer', 'admin', 'staff'] as $role) {
                $this->authenticateUser($role === 'customer' ? 10 : 20, $role);
                $createUrl = $role === 'customer' ? '/api/pets' : '/api/admin/customer-pets/registered/10';
                foreach ([['Dog', ' golden   retriever ', 'Wavy Coat'], ['Cat', 'Bengal', 'Longhair Coat'], ['Dog', 'Beagle', 'Smooth Short Coat']] as $index => [$species, $breed, $coat]) {
                    $payload = ['pet_name' => "$role named $index", 'species' => $species, 'breed' => $breed];
                    $this->postJson($createUrl, [...$payload, 'fur_type' => 'Unsupported'])->assertUnprocessable()->assertJsonValidationErrors('fur_type');
                    $id = $this->postJson($createUrl, $payload)->assertCreated()->assertJsonPath('pet.fur_type', null)->json('pet.pet_id');
                    $editUrl = ($role === 'customer' ? '/api/pets/' : '/api/admin/pets/').$id;
                    $this->putJson($editUrl, [...$payload, 'fur_type' => $coat])->assertOk()->assertJsonPath('pet.fur_type', $coat);
                    $change = $this->putJson($editUrl, [...$payload, 'breed' => 'My custom breed', 'fur_type' => null]);
                    if ($role === 'customer') $change->assertUnprocessable()->assertJsonValidationErrors(['breed', 'fur_type']);
                    else $change->assertOk()->assertJsonPath('pet.fur_type', null);
                }
                foreach (['My custom breed', 'Mixed Breed / Aspin', 'Unknown Breed', null] as $index => $breed) {
                    $this->postJson($createUrl, ['pet_name' => "$role optional $index", 'species' => 'Dog', 'breed' => $breed, 'fur_type' => 'Unknown Fur Type'])
                        ->assertCreated()->assertJsonPath('pet.fur_type', 'Unknown Fur Type');
                }
                $this->postJson($createUrl, ['pet_name' => "$role no species"])->assertUnprocessable()->assertJsonValidationErrors('species');
            }
        } finally { Schema::drop('users'); }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRegistrationAvailability();

        Schema::create('pets', function (Blueprint $table) {
            $table->json('customer_locked_fields')->nullable();
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
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
            $table->integer('queue_number')->nullable();
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
            $table->string('grooming_state')->default('not_started');
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
                $this->putJson($editUrl, [...$payload, 'size' => $size])->assertOk()->assertJsonPath('pet.size', $size);
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
                ->assertOk()->assertJsonPath('pet.size', $role === 'customer' ? 'large' : 'small');
            $clear = $this->putJson($editUrl, $unknown);
            if ($role === 'customer') $clear->assertUnprocessable()->assertJsonValidationErrors('weight');
            else $clear->assertOk()->assertJsonPath('pet.weight', null)->assertJsonPath('pet.size', 'large');
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

    public function test_customer_completes_blank_fields_once_and_cannot_replace_or_clear_them(): void
    {
        $this->authenticateCustomer(10);
        $id = $this->postJson('/api/pets', ['pet_name' => 'Andrew', 'species' => 'Dog'])
            ->assertCreated()->json('pet.pet_id');
        $this->putJson("/api/pets/$id", ['breed' => 'Golden Retriever'])
            ->assertOk()->assertJsonPath('pet.pet_name', 'Andrew')->assertJsonPath('pet.fur_type', null);
        $this->putJson("/api/pets/$id", ['fur_type' => 'Unknown Fur Type', 'weight' => 12, 'color' => 'White',
            'gender' => 'male', 'birthdate' => '2020-01-01', 'medical_conditions' => 'Shampoo allergy'])
            ->assertOk()->assertJsonPath('pet.size', 'medium');
        foreach (['pet_name' => 'Andy', 'species' => 'Cat', 'breed' => 'Beagle', 'fur_type' => null,
            'weight' => 14, 'size' => 'large', 'gender' => 'female', 'birthdate' => null,
            'color' => null, 'medical_conditions' => null] as $field => $value) {
            $this->putJson("/api/pets/$id", [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseHas('pets', ['pet_id' => $id, 'breed' => 'Golden Retriever', 'fur_type' => 'Unknown Fur Type', 'weight' => 12]);
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_new_pets_require_explicit_species(): void
    {
        $this->authenticateCustomer(10);
        foreach ([[], ['species' => null], ['species' => '']] as $data) {
            $this->postJson('/api/pets', ['pet_name' => 'Andrew', ...$data])->assertUnprocessable()->assertJsonValidationErrors('species');
        }
    }

    public function test_stale_customer_model_cannot_replace_a_concurrently_completed_field(): void
    {
        $this->authenticateCustomer(10);
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog']);
        $first = \App\Models\Pet::find(101);
        $stale = \App\Models\Pet::find(101);
        $first->update(['breed' => 'Beagle']);
        try {
            $stale->update(['breed' => 'Persian']);
            $this->fail('A stale submission must not replace saved information.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('breed', $error->errors());
        }
        $this->assertDatabaseHas('pets', ['pet_id' => 101, 'breed' => 'Beagle']);
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
             'message' => "Bethlehem Animal Clinic updated Mochi's species, breed, fur type, gender, birthdate, weight, size, neutered status, and neutered date.",
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

    public function test_either_verified_measurement_protects_both_fields_even_when_blank(): void
    {
        $this->authenticateCustomer(10);
        foreach (['weight', 'size'] as $index => $verified) {
            $id = 101 + $index;
            DB::table('pets')->insert(['pet_id' => $id, 'user_id' => 10, 'pet_name' => "Pet $id", 'species' => 'Dog',
                'clinic_verified_fields' => json_encode([$verified])]);
            foreach (['weight' => 12, 'size' => 'medium'] as $field => $value) {
                $this->putJson("/api/pets/$id", [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            }
            $this->assertDatabaseHas('pets', ['pet_id' => $id, 'weight' => null, 'size' => null]);
        }
    }

    public function test_staff_changes_notify_once_and_unchanged_or_rolled_back_updates_do_not_notify(): void
    {
        $this->authenticateUser(20, 'staff');
        $pet = \App\Models\Pet::create(['user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog']);
        $this->assertDatabaseCount('customer_notifications', 0);
        $pet->update(['weight' => 12, 'size' => 'medium']);
        $this->assertDatabaseHas('customer_notifications', ['message' => "Bethlehem Animal Clinic updated Andrew's weight and size."]);
        $pet->update(['weight' => 12, 'size' => 'medium']);
        $pet->update(['is_archived' => true]);
        $this->assertDatabaseCount('customer_notifications', 1);
        $pet->update(['pet_name' => 'Andy']);
        $this->assertDatabaseHas('customer_notifications', ['message' => "Bethlehem Animal Clinic changed your pet's name from Andrew to Andy."]);
        DB::beginTransaction();
        $pet->update(['color' => 'White']);
        DB::rollBack();
        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertDatabaseHas('pets', ['pet_id' => $pet->pet_id, 'color' => null]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clearedProfileCases')]
    public function test_customer_cannot_reenter_a_field_after_staff_clears_its_saved_value(bool $legacy): void
    {
        $this->authenticateCustomer(10);
        if ($legacy) {
            DB::table('pets')->insert(['pet_id' => 201, 'user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog', 'color' => 'White']);
            $id = 201;
        } else {
            $id = $this->postJson('/api/pets', ['pet_name' => 'Andrew', 'species' => 'Dog', 'color' => 'White'])
                ->assertCreated()->json('pet.pet_id');
        }
        $this->authenticateUser(20, 'staff');
        $this->putJson("/api/admin/pets/$id", ['pet_name' => 'Andrew', 'species' => 'Dog', 'color' => null])->assertOk();
        $this->authenticateCustomer(10);
        $this->putJson("/api/pets/$id", ['color' => 'Black'])->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->assertDatabaseHas('pets', ['pet_id' => $id, 'color' => null]);
        $this->putJson("/api/pets/$id", ['medical_conditions' => 'Gentle handling'])->assertOk();
    }

    public static function clearedProfileCases(): array
    {
        return ['new pet' => [false], 'legacy profile without lock metadata' => [true]];
    }

    public function test_staff_unchanged_verified_size_is_preserved_without_a_notification(): void
    {
        $this->authenticateUser(20, 'staff');
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog',
            'weight' => 12, 'size' => 'small', 'clinic_verified_fields' => json_encode(['size'])]);
        $this->putJson('/api/admin/pets/101', ['pet_name' => 'Andrew', 'species' => 'Dog', 'weight' => 12, 'size' => 'small'])
            ->assertOk()->assertJsonPath('pet.size', 'small');
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_stale_staff_update_preserves_a_concurrent_clinic_verification(): void
    {
        $this->authenticateUser(20, 'staff');
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog', 'weight' => 12, 'size' => 'small']);
        $stale = \App\Models\Pet::find(101);
        $staleVerification = \App\Models\Pet::find(101);
        \App\Models\Pet::find(101)->confirmClinicSize('small');
        $stale->breed = 'Beagle';
        $stale->clinic_verified_fields = ['breed'];
        $stale->save();
        $this->assertSame(['size', 'breed'], \App\Models\Pet::find(101)->clinic_verified_fields);
        $staleVerification->clinic_verified_fields = ['fur_type'];
        $staleVerification->save();
        $this->assertSame(['size', 'breed', 'fur_type'], \App\Models\Pet::find(101)->clinic_verified_fields);
        $this->assertDatabaseCount('customer_notifications', 1);
    }

    public function test_staff_multi_field_and_name_corrections_use_one_notification_and_skip_unregistered_pets(): void
    {
        $this->authenticateUser(20, 'staff');
        $pet = \App\Models\Pet::create(['user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog']);
        $pet->update(['breed' => 'Beagle', 'fur_type' => 'Unknown Fur Type', 'color' => 'White']);
        $this->assertDatabaseCount('customer_notifications', 1);
        $this->assertDatabaseHas('customer_notifications', ['message' => "Bethlehem Animal Clinic updated Andrew's breed, fur type, and color."]);
        $pet->update(['pet_name' => 'Andy', 'weight' => 8, 'size' => 'small']);
        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertDatabaseHas('customer_notifications', ['message' => "Bethlehem Animal Clinic changed your pet's name from Andrew to Andy and updated weight and size."]);
        $offline = \App\Models\Pet::create(['pet_name' => 'Offline pet', 'species' => 'Dog']);
        $offline->update(['color' => 'Black']);
        $this->assertDatabaseCount('customer_notifications', 2);
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
