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
    use \Tests\Support\CreatesPetBodyConditionSchema;
    use \Tests\Support\CreatesRegistrationAvailability;

    public function test_customer_can_save_an_optional_body_condition_but_cannot_self_verify_it(): void
    {
        $this->authenticateCustomer(10);
        $profile = ['pet_name' => 'Mochi', 'species' => 'Dog', 'size' => 'medium'];
        $this->postJson('/api/pets', $profile + ['body_condition' => 'unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors('body_condition');
        $id = $this->postJson('/api/pets', $profile + ['body_condition' => 'overweight', 'clinic_verified_fields' => ['body_condition']])
            ->assertCreated()->assertJsonPath('pet.body_condition', 'overweight')->json('pet.pet_id');
        $this->assertDatabaseHas('pets', ['pet_id' => $id, 'body_condition' => 'overweight', 'clinic_verified_fields' => null]);
    }

    public function test_customer_body_condition_changes_only_remove_their_own_verification(): void
    {
        $this->authenticateCustomer(10);
        (require database_path('migrations/2026_10_25_000004_create_activity_logs_table.php'))->up();
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'Dog']);
        foreach (['thin', null] as $index => $newCondition) {
            DB::table('pets')->where('pet_id', 101)->update(['body_condition' => 'obese',
                'clinic_verified_fields' => json_encode(['size', 'body_condition'])]);
            $events = DB::table('activity_logs')->count();
            $this->putJson('/api/pets/101', ['body_condition' => 'obese'])->assertOk()
                ->assertJsonPath('pet.clinic_verified_fields', ['size', 'body_condition']);
            $this->assertDatabaseCount('activity_logs', $events);
            $notes = "Shampoo note $index";
            $this->putJson('/api/pets/101', ['body_condition' => 'obese', 'medical_conditions' => $notes])->assertOk()
                ->assertJsonPath('pet.medical_conditions', $notes)
                ->assertJsonPath('pet.clinic_verified_fields', ['size', 'body_condition']);
            $this->putJson('/api/pets/101', ['body_condition' => $newCondition])->assertOk()
                ->assertJsonPath('pet.body_condition', $newCondition)->assertJsonPath('pet.clinic_verified_fields', ['size']);
        }
    }

    public function test_staff_can_confirm_an_unchanged_customer_condition_and_clear_it(): void
    {
        (require database_path('migrations/2026_10_25_000004_create_activity_logs_table.php'))->up();
        $this->authenticateUser(20, 'staff', 'grooming', 'grooming_receptionist');
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'Dog',
            'body_condition' => 'obese', 'clinic_verified_fields' => json_encode(['size'])]);
        $profile = ['pet_name' => 'Mochi', 'body_condition' => 'obese'];
        $this->putJson('/api/admin/pets/101', $profile)->assertOk()
            ->assertJsonPath('pet.clinic_verified_fields', ['size', 'body_condition']);
        $this->putJson('/api/admin/pets/101', $profile)->assertOk();
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('customer_notifications', 0);
        $this->putJson('/api/admin/pets/101', [...$profile, 'body_condition' => null])->assertOk()
            ->assertJsonPath('pet.body_condition', null)->assertJsonPath('pet.clinic_verified_fields', ['size']);
        $this->assertDatabaseCount('customer_notifications', 1);
    }

    public function test_body_condition_migration_backfills_latest_finalized_assessment_and_pet_list_reads_profile(): void
    {
        $this->authenticateCustomer(10);
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'Cat', 'weight' => 4, 'size' => 'small']);
        DB::table('pets')->insert(['pet_id' => 102, 'user_id' => 10, 'pet_name' => 'Miso', 'species' => 'Cat']);
        foreach ([
            [1, 101, 'completed', '2026-01-01', 5],
            [2, 101, 'completed', '2026-02-01', 4],
            [3, 101, 'for_payment', '2026-02-01', 7],
            [4, 101, 'in_consultation', '2026-03-01', 9],
            [5, 101, 'completed', '2026-04-01', null],
            [6, 101, 'completed', '2026-05-01', 9], // No medical record.
            [7, 102, 'in_consultation', '2026-03-01', 9],
            [8, 101, 'completed', '2025-01-01', 9], // Higher ID, earlier visit.
        ] as [$id, $petId, $status, $date, $score]) {
            DB::table('clinic_appointments')->insert(['id' => $id, 'pet_id' => $petId, 'status' => $status, 'appointment_date' => $date]);
            DB::table('clinic_vitals')->insert(['clinic_appointment_id' => $id, 'body_condition_score' => $score]);
            if ($id !== 6) DB::table('clinic_records')->insert(['clinic_appointment_id' => $id]);
        }

        Schema::table('pets', fn (Blueprint $table) => $table->dropColumn('body_condition'));
        (require database_path('migrations/2026_11_10_000001_add_body_condition_to_pets.php'))->up();
        DB::enableQueryLog();
        $pets = collect($this->getJson('/api/pets')->assertOk()->json('pets'))->keyBy('pet_id');
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'clinic_vitals'));
        DB::disableQueryLog();
        $this->assertCount(0, $queries);
        $this->assertSame('overweight', $pets[101]['body_condition']);
        $this->assertSame(['body_condition'], $pets[101]['clinic_verified_fields']);
        $this->assertNull($pets[102]['body_condition']);
        $this->assertArrayNotHasKey('body_condition_score', $pets[101]);

        $this->putJson('/api/pets/101', ['body_condition' => 'thin'])->assertOk();
        $this->getJson('/api/pets/101')->assertOk()->assertJsonPath('pet.body_condition', 'thin');
    }

    public function test_customer_weight_injection_is_ignored_on_creation_and_update(): void
    {
        $this->authenticateCustomer(10);
        $id = $this->postJson('/api/pets', ['pet_name' => 'Mochi', 'species' => 'Dog', 'size' => 'large', 'weight' => 8])
            ->assertCreated()->assertJsonPath('pet.size', 'large')->json('pet.pet_id');
        $this->assertDatabaseHas('pets', ['pet_id' => $id, 'weight' => null]);
        $this->putJson("/api/pets/$id", ['weight' => 12])->assertOk()->assertJsonPath('pet.weight', null);

        DB::table('pets')->where('pet_id', $id)->update(['weight' => 28.5]);
        $this->putJson("/api/pets/$id", ['medical_conditions' => 'Gentle handling'])->assertOk()->assertJsonPath('pet.weight', 28.5);
        $this->putJson("/api/pets/$id", ['weight' => null, 'medical_conditions' => 'Shampoo allergy'])
            ->assertOk()->assertJsonPath('pet.weight', 28.5)->assertJsonPath('pet.medical_conditions', 'Shampoo allergy');
        $this->putJson("/api/pets/$id", ['weight' => 12])->assertOk()->assertJsonPath('pet.weight', 28.5);
        $this->putJson("/api/admin/pets/$id", ['pet_name' => 'Changed'])->assertForbidden();
    }

    public function test_staff_corrections_keep_actor_attribution_notifications_and_safe_activity_details(): void
    {
        (require database_path('migrations/2026_10_25_000004_create_activity_logs_table.php'))->up();
        foreach ([
            ['admin', null, null, 'Admin'],
            ['staff', 'grooming', 'grooming_receptionist', 'Grooming Receptionist'],
            ['staff', 'clinic', 'clinic_receptionist', 'Clinic Receptionist'],
            ['staff', 'clinic', 'veterinarian', 'Veterinarian'],
            ['staff', null, null, 'Staff'],
        ] as $index => [$role, $type, $subrole, $roleLabel]) {
            $id = $index + 101;
            $actorId = $index + 20;
            DB::table('pets')->insert(['pet_id' => $id, 'user_id' => $index === 4 ? null : 10,
                'unregistered_customer_id' => $index === 4 ? 42 : null,
                'pet_name' => "Pet $id", 'species' => 'Dog', 'birthdate' => '2020-01-01',
                'medical_conditions' => 'Previous private note', 'body_condition' => 'obese']);
            $this->authenticateUser($actorId, $role, $type, $subrole);
            if ($role === 'admin') auth()->user()->forceFill(['username' => 'clinic-admin']);
            $this->putJson("/api/admin/pets/$id", ['pet_name' => "Updated $id", 'birthdate' => '2021-01-01',
                'is_neutered' => true, 'medical_conditions' => 'New private note', 'body_condition' => 'ideal'])->assertOk()
                ->assertJsonPath('pet.clinic_verified_fields', ['body_condition']);

            $activity = \App\Models\ActivityLog::where('action', 'pet_updated')->where('pet_id', $id)->sole();
            $this->assertSame($actorId, $activity->actor_id);
            $this->assertSame($roleLabel, $activity->actor_role);
            $this->assertSame($role === 'admin' ? 'clinic-admin' : "Jamie · $roleLabel", $activity->actor_display);
            $this->assertStringContainsString('Jamie Santos', $activity->actor_identity);
            $this->assertSame('pet', $activity->subject_type);
            $this->assertSame((string) $id, $activity->subject_id);
            $this->assertNotNull($activity->occurred_at);
            $this->assertSame(['before' => "Pet $id", 'after' => "Updated $id"], $activity->changes['pet_name']);
            $this->assertSame(['before' => '2020-01-01', 'after' => '2021-01-01'], $activity->changes['birthdate']);
            $this->assertArrayHasKey('is_neutered', $activity->changes);
            $this->assertSame(['before' => 'obese', 'after' => 'ideal'], $activity->changes['body_condition']);
            $this->assertArrayNotHasKey('medical_conditions', $activity->changes);
            $this->assertSame('Pet information updated', \App\Support\ActivityPresentation::title($activity));
        }
        $group = collect(\App\Support\ActivityPresentation::typeGroups())->firstWhere('label', 'Customers & Pets');
        $this->assertContains(['value' => 'pet_updated', 'label' => 'Pet information updated'], $group['options']);
        $this->assertDatabaseCount('customer_notifications', 4);
    }

    public function test_birthdate_creation_range_is_consistent_for_customer_and_admin_owners(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 16:01:00', 'UTC'));
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role');
        });
        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id(); $table->boolean('is_archived')->default(false);
            $table->timestamp('account_deleted_at')->nullable();
        });
        DB::table('users')->insert(['user_id' => 10, 'role' => 'customer']);
        DB::table('unregistered_customers')->insert(['id' => 42]);
        try {
            foreach (['/api/pets', '/api/admin/customer-pets/registered/10', '/api/admin/customer-pets/unregistered/42'] as $url) {
                $this->authenticateUser(10, $url === '/api/pets' ? 'customer' : 'admin');
                foreach (['2026-11-04', '1986-11-02', '1700-01-01', '2026-02-30', '2024-02-30', '2026-1-01', 'November 2, 2026'] as $date) {
                    $this->postJson($url, ['pet_name' => 'Invalid date', 'species' => 'Dog', 'birthdate' => $date])
                        ->assertUnprocessable()->assertJsonValidationErrors('birthdate');
                }
                foreach (['2026-11-03', '1986-11-03', '2024-02-29', null, ''] as $index => $date) {
                    $this->postJson($url, ['pet_name' => md5($url).$index, 'species' => 'Dog', 'birthdate' => $date])
                        ->assertCreated()->assertJsonPath('pet.birthdate', $date ?: null);
                }
            }
        } finally {
            Schema::drop('users'); Schema::drop('unregistered_customers');
        }
    }

    public function test_legacy_birthdate_is_preserved_and_only_authorized_valid_changes_are_allowed(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-03 10:00:00', 'Asia/Manila'));
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Legacy', 'species' => 'Dog', 'birthdate' => '1700-01-01']);
        $this->authenticateCustomer(10);
        $this->putJson('/api/pets/101', ['birthdate' => '1700-01-01', 'medical_conditions' => 'Gentle handling'])
            ->assertOk()->assertJsonPath('pet.birthdate', '1700-01-01');
        $this->putJson('/api/pets/101', ['birthdate' => '2020-01-01'])->assertUnprocessable()->assertJsonValidationErrors('birthdate');
        $this->authenticateUser(20, 'admin');
        $this->putJson('/api/admin/pets/101', ['pet_name' => 'Legacy', 'birthdate' => '1700-01-01', 'color' => 'Brown'])
            ->assertOk()->assertJsonPath('pet.birthdate', '1700-01-01');
        $this->putJson('/api/admin/pets/101', ['pet_name' => 'Legacy', 'color' => 'Black'])
            ->assertOk()->assertJsonPath('pet.birthdate', '1700-01-01');
        foreach (['1700-01-02', '1986-11-02', '2026-11-04', '2026-02-30'] as $date) {
            $this->putJson('/api/admin/pets/101', ['pet_name' => 'Legacy', 'birthdate' => $date])
                ->assertUnprocessable()->assertJsonValidationErrors('birthdate');
        }
        $this->putJson('/api/admin/pets/101', ['pet_name' => 'Legacy', 'birthdate' => '1986-11-03'])
            ->assertOk()->assertJsonPath('pet.birthdate', '1986-11-03');
        $this->putJson('/api/admin/pets/101', ['pet_name' => 'Legacy', 'birthdate' => '2026-11-03'])
            ->assertOk()->assertJsonPath('pet.birthdate', '2026-11-03');
    }

    public function test_customer_can_fill_an_empty_birthdate_once_with_a_valid_date(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-03 10:00:00', 'Asia/Manila'));
        $this->authenticateCustomer(10);
        $id = $this->postJson('/api/pets', ['pet_name' => 'Young pet', 'species' => 'Cat'])->assertCreated()->json('pet.pet_id');
        foreach (['2026-11-04', '1986-11-02', '2026-02-30'] as $invalid) {
            $this->putJson('/api/pets/'.$id, ['birthdate' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('birthdate');
        }
        $this->putJson('/api/pets/'.$id, ['birthdate' => '2026-11-03'])->assertOk()->assertJsonPath('pet.birthdate', '2026-11-03');
        $this->putJson('/api/pets/'.$id, ['birthdate' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors('birthdate');
    }

    public function test_customer_cannot_read_update_archive_or_restore_another_owners_pet(): void
    {
        DB::table('pets')->insert([
            ['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Own pet', 'species' => 'Dog'],
            ['pet_id' => 202, 'user_id' => 20, 'pet_name' => 'Private pet', 'species' => 'Cat'],
        ]);
        $this->authenticateCustomer(10);
        $this->getJson('/api/pets')->assertOk()->assertJsonCount(1, 'pets')
            ->assertJsonMissing(['pet_name' => 'Private pet']);
        $this->getJson('/api/pets/202')->assertNotFound();
        $this->putJson('/api/pets/202', ['user_id' => 10, 'pet_name' => 'Stolen pet'])->assertNotFound();
        $this->postJson('/api/pets/202/archive')->assertNotFound();
        $this->postJson('/api/pets/202/unarchive')->assertNotFound();
        $this->assertDatabaseHas('pets', ['pet_id' => 202, 'user_id' => 20, 'pet_name' => 'Private pet', 'is_archived' => false]);
        $this->getJson('/api/pets/101')->assertOk()->assertJsonPath('pet.pet_name', 'Own pet');
    }

    public function test_customer_pet_writes_ignore_submitted_ownership_and_verification_properties(): void
    {
        $this->authenticateCustomer(10);
        $protected = ['user_id' => 20, 'unregistered_customer_id' => 42, 'is_archived' => true,
            'clinic_verified_fields' => ['weight'], 'is_deceased' => true];
        $id = $this->postJson('/api/pets', [...$protected, 'pet_name' => 'Mochi', 'species' => 'Dog'])
            ->assertCreated()->json('pet.pet_id');
        $this->putJson('/api/pets/'.$id, [...$protected, 'color' => 'Brown'])->assertOk();
        $this->assertDatabaseHas('pets', ['pet_id' => $id, 'user_id' => 10, 'unregistered_customer_id' => null,
            'is_archived' => false, 'is_deceased' => false, 'clinic_verified_fields' => null, 'color' => 'Brown']);
    }

    public function test_customer_cannot_mark_another_owners_notification_read(): void
    {
        $id = DB::table('customer_notifications')->insertGetId([
            'user_id' => 20, 'type' => 'reminder_24h', 'message' => 'Private notification', 'is_read' => false,
        ]);
        $this->authenticateCustomer(10);
        $this->patchJson('/api/customer/notifications/'.$id.'/read', ['user_id' => 20])->assertNotFound();
        $this->patchJson('/api/customer/notifications/read-all', ['user_id' => 20])->assertOk();
        $this->assertDatabaseHas('customer_notifications', ['id' => $id, 'user_id' => 20, 'is_read' => false]);
    }

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
                        ->assertUnprocessable()->assertJsonValidationErrors('color');
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
            $table->string('body_condition')->nullable();
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

        $this->createPetBodyConditionSchema();

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
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('clinic_vitals');
        Schema::dropIfExists('clinic_records');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('pets');

        parent::tearDown();
    }

    public function test_admin_and_staff_add_and_edit_share_exact_weight_rules(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role');
        });
        DB::table('users')->insert(['user_id' => 10, 'role' => 'customer']);
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true);
        foreach (['admin', 'staff'] as $role) {
            $this->authenticateUser(20, $role);
            $createUrl = '/api/admin/customer-pets/registered/10';
            foreach ($cases['pets'] as $index => [$species, $weight, $size]) {
                $payload = ['pet_name' => $role.' pet '.$index, 'species' => $species,
                    'weight' => $weight, 'size' => $size === 'small' ? 'medium' : 'small'];
                $id = $this->postJson($createUrl, $payload)->assertCreated()->assertJsonPath('pet.size', $size)->json('pet.pet_id');
                $editUrl = '/api/admin/pets/'.$id;
                $this->putJson($editUrl, [...$payload, 'size' => $size])->assertOk()->assertJsonPath('pet.size', $size);
                $this->assertDatabaseHas('pets', ['pet_id' => $id, 'weight' => $weight, 'size' => $size]);
            }
            foreach ([...$cases['invalid_weights'], ...$cases['below_profile_minimum']] as $index => $weight) {
                $this->postJson($createUrl, ['pet_name' => 'Invalid '.$index, 'species' => 'Dog', 'weight' => $weight])
                    ->assertUnprocessable()->assertJsonValidationErrors('weight');
                $this->putJson($editUrl, [...$payload, 'weight' => $weight])
                    ->assertUnprocessable()->assertJsonValidationErrors('weight');
                $this->assertDatabaseHas('pets', ['pet_id' => $id, 'weight' => $payload['weight'], 'size' => $size]);
            }
            $unknown = ['pet_name' => $role.' unknown', 'species' => 'Dog', 'weight' => null, 'size' => 'large'];
            $id = $this->postJson($createUrl, $unknown)->assertCreated()->assertJsonPath('pet.size', 'large')->json('pet.pet_id');
            $this->putJson('/api/admin/pets/'.$id, $unknown)
                ->assertOk()->assertJsonPath('pet.size', 'large');
            $editUrl = '/api/admin/pets/'.$id;
            $this->putJson($editUrl, [...$unknown, 'weight' => 4.25])
                ->assertOk()->assertJsonPath('pet.size', 'small');
            $clear = $this->putJson($editUrl, $unknown);
            $clear->assertOk()->assertJsonPath('pet.weight', null)->assertJsonPath('pet.size', 'large');
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

    public function test_clinic_patient_editor_accepts_small_weights_only_on_the_authorized_route(): void
    {
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog',
            'weight' => 0.25, 'size' => 'medium', 'clinic_verified_fields' => json_encode(['size'])]);
        $ordinary = '/api/admin/pets/101';
        $clinical = '/api/admin/clinic/pets/101';
        $profile = ['pet_name' => 'Andrew', 'species' => 'Dog'];

        $this->authenticateUser(20, 'staff');
        $this->putJson($clinical, [...$profile, 'weight' => 0.1])->assertForbidden();
        $this->putJson($ordinary, [...$profile, 'weight' => 0.1, 'clinical' => true])
            ->assertUnprocessable()->assertJsonPath('errors.weight.0', 'Weight must be at least 0.5 kg.');
        $this->putJson($ordinary, [...$profile, 'weight' => '0.25', 'medical_conditions' => 'Sensitive skin'])
            ->assertOk()->assertJsonPath('pet.weight', 0.25)->assertJsonPath('pet.size', 'medium');

        $this->authenticateUser(21, 'staff', 'clinic', 'veterinarian');
        foreach (['0.10', '0.01'] as $weight) {
            $this->putJson($clinical, [...$profile, 'weight' => $weight])
                ->assertOk()->assertJsonPath('pet.weight', (float) $weight);
        }
        foreach (['155.61', '0.010', '1e-2', '0', '-1'] as $weight) {
            $this->putJson($clinical, [...$profile, 'weight' => $weight])
                ->assertUnprocessable()->assertJsonValidationErrors('weight');
        }
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
            'size' => 'small',
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
        $this->putJson("/api/pets/$id", ['fur_type' => 'Unknown Fur Type', 'size' => 'medium', 'color' => 'White',
            'gender' => 'male', 'birthdate' => '2020-01-01', 'medical_conditions' => 'Shampoo allergy'])
            ->assertOk()->assertJsonPath('pet.size', 'medium');
        foreach (['pet_name' => 'Andy', 'species' => 'Cat', 'breed' => 'Beagle', 'fur_type' => null,
            'size' => 'large', 'gender' => 'female', 'birthdate' => null,
            'color' => null] as $field => $value) {
            $this->putJson("/api/pets/$id", [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseHas('pets', ['pet_id' => $id, 'breed' => 'Golden Retriever', 'fur_type' => 'Unknown Fur Type', 'size' => 'medium', 'weight' => null]);
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_customer_can_repeatedly_edit_and_clear_care_notes_with_a_legacy_lock(): void
    {
        $this->authenticateCustomer(10);
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Andrew', 'species' => 'Dog',
            'weight' => 0.1, 'customer_locked_fields' => json_encode(['medical_conditions', 'color'])]);
        foreach (['Gentle handling', 'Shampoo allergy', null, 'Sensitive skin'] as $notes) {
            $this->putJson('/api/pets/101', ['medical_conditions' => $notes, 'weight' => '0.10'])
                ->assertOk()->assertJsonPath('pet.medical_conditions', $notes)->assertJsonPath('pet.weight', 0.1);
        }
        $this->putJson('/api/pets/101', ['medical_conditions' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('medical_conditions');
        $this->putJson('/api/pets/101', ['medical_conditions' => null, 'expected_medical_conditions' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_medical_conditions');
        $this->assertDatabaseHas('pets', ['pet_id' => 101, 'medical_conditions' => 'Sensitive skin']);
        $this->putJson('/api/pets/101', ['color' => 'White'])->assertUnprocessable()->assertJsonValidationErrors('color');
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
                '/account/pets/details?pet_id=101&tab=overview',
            );
    }

    public function test_either_verified_measurement_protects_size_even_when_blank(): void
    {
        $this->authenticateCustomer(10);
        foreach (['weight', 'size'] as $index => $verified) {
            $id = 101 + $index;
            DB::table('pets')->insert(['pet_id' => $id, 'user_id' => 10, 'pet_name' => "Pet $id", 'species' => 'Dog',
                'clinic_verified_fields' => json_encode([$verified])]);
            $this->putJson("/api/pets/$id", ['size' => 'medium'])->assertUnprocessable()->assertJsonValidationErrors('size');
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

        (require database_path('migrations/2026_11_10_000002_add_pet_profile_snapshots.php'))->up();
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

    private function authenticateUser(int $userId, string $role, ?string $staffType = null, ?string $staffSubrole = null): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'role' => $role,
            'staff_type' => $staffType,
            'staff_subrole' => $staffSubrole,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }
}
