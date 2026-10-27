<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminVaccinationController;
use App\Http\Controllers\PetVaccinationController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminVaccinationApiTest extends TestCase
{
    private $vaccinationMigration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role');
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('appointment_reference');
            $table->string('status')->default('in_consultation');
            $table->string('case_type')->default('vaccination');
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->timestamp('consultation_finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->nullable();
        });

        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable();
        });
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->unsignedInteger('primary_veterinarian_id')->nullable();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->autoIncrement();
            $table->string('item_name');
            $table->string('category');
            $table->string('unit')->default('vial');
            $table->decimal('reorder_level', 10, 2)->default(0);
            $table->decimal('unit_cost', 8, 2)->default(0);
            $table->decimal('selling_price', 8, 2)->nullable();
            $table->decimal('quantity_on_hand', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->unsignedInteger('transaction_id')->autoIncrement();
            $table->unsignedInteger('item_id');
            $table->string('type');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_time', 8, 2)->nullable();
            $table->decimal('selling_price_at_time', 8, 2)->nullable();
            $table->string('reason');
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reference_type', 30)->default('manual');
            $table->unsignedInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->vaccinationMigration = require base_path(
            'database/migrations/2026_07_23_000004_create_vaccination_records_table.php',
        );
        $this->vaccinationMigration->up();
        (require database_path('migrations/2026_10_12_000001_extend_vaccination_records_for_treatments.php'))->up();
        (require base_path('database/migrations/2026_10_10_000001_create_clinic_charges_table.php'))->up();
        (require database_path('migrations/2026_10_10_000002_add_clinic_inventory_pricing.php'))->up();
        (require database_path('migrations/2026_10_11_000001_add_inventory_weight_pricing_mode.php'))->up();
        (require database_path('migrations/2026_10_12_000004_separate_treatment_clinical_details_from_inventory.php'))->up();
        (require database_path('migrations/2026_10_13_000003_link_treatment_inventory_movements.php'))->up();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('clinic_confinements')) {
            if (Schema::hasTable('clinic_confinement_charges')) (require database_path('migrations/2026_10_14_000002_configure_confinement_charges.php'))->down();
            (require database_path('migrations/2026_10_13_000004_create_clinic_confinements.php'))->down();
            (require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php'))->down();
            (require database_path('migrations/2026_10_13_000001_create_clinic_services_table.php'))->down();
        }
        (require database_path('migrations/2026_10_13_000003_link_treatment_inventory_movements.php'))->down();
        Schema::dropIfExists('clinic_records');
        Carbon::setTestNow();

        if (Schema::hasTable('vaccination_records')) {
            $this->vaccinationMigration->down();
        }

        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_item_weight_prices');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('clinic_vitals');
        (require base_path('database/migrations/2026_10_10_000001_create_clinic_charges_table.php'))->down();
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_unauthenticated_requests_receive_unauthorized_responses(): void
    {
        $this->getJson('/api/admin/vaccination-options')->assertUnauthorized();
        $this->getJson('/api/admin/pets/101/vaccinations')->assertUnauthorized();
        $this->postJson('/api/admin/pets/101/vaccinations', [
            'inventory_item_id' => 301,
            'administered_date' => '2026-07-23',
        ])->assertUnauthorized();
    }

    public function test_customer_is_forbidden_from_every_staff_vaccination_action(): void
    {
        $this->authenticateAs('customer', 10);

        $requests = [
            fn () => $this->getJson('/api/admin/vaccination-options'),
            fn () => $this->getJson('/api/admin/pets/101/vaccinations'),
            fn () => $this->postJson('/api/admin/pets/101/vaccinations'),
            fn () => $this->getJson('/api/admin/pets/101/vaccinations/1'),
            fn () => $this->patchJson('/api/admin/pets/101/vaccinations/1'),
            fn () => $this->postJson('/api/admin/pets/101/vaccinations/1/publish'),
            fn () => $this->postJson('/api/admin/pets/101/vaccinations/1/void'),
        ];

        foreach ($requests as $request) {
            $request()
                ->assertForbidden()
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Forbidden. You do not have permission to access this resource.',
                ]);
        }
    }

    #[DataProvider('authorizedRoles')]
    public function test_staff_and_admin_can_create_and_list_vaccination_drafts(string $role): void
    {
        $userId = $role === 'admin' ? 1 : 2;
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs($role, $userId);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'FVRCP Vaccine', 'vaccine', 0);

        $this->postJson('/api/admin/pets/101/vaccinations', $this->validDraft(301))
            ->assertCreated()
            ->assertJsonPath('vaccination.state', 'draft')
            ->assertJsonPath('vaccination.vaccine_name', 'FVRCP Vaccine')
            ->assertJsonPath('vaccination.administered_date', '2026-07-23')
            ->assertJsonPath('vaccination.recorded_by_user_id', $userId)
            ->assertJsonPath('vaccination.published_at', null)
            ->assertJsonPath('vaccination.voided_at', null);

        $this->getJson('/api/admin/pets/101/vaccinations')
            ->assertOk()
            ->assertJsonPath('pet.pet_name', 'Mochi')
            ->assertJsonCount(1, 'vaccinations')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'FVRCP Vaccine');
    }

    public static function authorizedRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
    }

    public function test_options_include_unavailable_products_with_usable_balances_without_provider_options(): void
    {
        Carbon::setTestNow('2026-07-23 09:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertRabiesStock(301);
        $this->insertInventoryItem(302, 'Empty Vaccine', 'vaccine', 0);
        $this->insertInventoryItem(303, 'Expired Vaccine', 'vaccine', 8);
        $this->insertStockIn(303, 8, 'OLD', '2026-07-01', '2026-06-01');
        $this->insertInventoryItem(304, 'Inactive Vaccine', 'vaccine', 0, ['is_active' => false]);
        DB::table('inventory_items')->where('item_id', 301)->update(['reorder_level' => 5]);
        $items = collect($this->getJson('/api/admin/vaccination-options')->assertOk()
            ->assertJsonMissingPath('veterinarians')->json('vaccines'))->keyBy('item_id');
        $this->assertCount(4, $items);
        $this->assertSame(5, $items[301]['available_quantity']);
        $this->assertSame('low_stock', $items[301]['stock_status']);
        $this->assertSame('LOT-EARLY', $items[301]['next_batch']['batch_number']);
        $this->assertSame('out_of_stock', $items[302]['stock_status']);
        $this->assertSame('expired', $items[303]['stock_status']);
        $this->assertSame(0, $items[303]['available_quantity']);
        $this->assertFalse($items[304]['is_active']);
        $this->assertArrayNotHasKey('deduction_supported', $items[301]);
        $this->assertArrayNotHasKey('dose_unit', $items[301]);
    }

    public function test_medical_record_options_reuse_active_veterinarians_without_loading_inventory(): void
    {
        $this->authenticateAs('admin', 1);
        $this->insertUser(2, 'Active', 'Vet', 'staff', 'veterinarian');
        $this->insertUser(3, 'Inactive', 'Vet', 'staff', 'veterinarian', false);
        $this->insertUser(4, 'Reception', 'Staff', 'staff', 'clinic_receptionist');
        $queries = [];
        DB::listen(function ($event) use (&$queries) { $queries[] = $event->sql; });
        $this->getJson('/api/admin/vaccination-options?clinical_only=1')->assertOk()
            ->assertJsonCount(1, 'veterinarians')->assertJsonPath('veterinarians.0.user_id', 2)
            ->assertJsonPath('current_user_is_veterinarian', false)->assertJsonMissingPath('vaccines')
            ->assertJsonPath('administration_routes', \App\Models\VaccinationRecord::ADMINISTRATION_ROUTES);
        $this->assertFalse(collect($queries)->contains(fn ($query) => str_contains($query, 'inventory')));
    }

    public function test_listing_is_pet_scoped_sorted_and_includes_each_state_and_due_status(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');

        $this->insertVaccination(101, 'Older Draft', '2026-05-01');
        $this->insertVaccination(101, 'Published', '2026-07-20', [
            'next_due_date' => '2026-08-01',
            'published_at' => '2026-07-20 10:00:00',
            'published_by_user_id' => 2,
        ]);
        $this->insertVaccination(101, 'Voided', '2026-07-22', [
            'published_at' => '2026-07-22 10:00:00',
            'published_by_user_id' => 2,
            'voided_at' => '2026-07-22 11:00:00',
            'voided_by_user_id' => 2,
            'void_reason' => 'Duplicate.',
        ]);
        $otherPetRecord = $this->insertVaccination(102, 'Other Pet', '2026-07-23');

        $response = $this->getJson('/api/admin/pets/101/vaccinations')
            ->assertOk()
            ->assertJsonCount(3, 'vaccinations')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'Voided')
            ->assertJsonPath('vaccinations.0.state', 'voided')
            ->assertJsonPath('vaccinations.1.vaccine_name', 'Published')
            ->assertJsonPath('vaccinations.1.state', 'published')
            ->assertJsonPath('vaccinations.1.due_status', 'due_soon')
            ->assertJsonPath('vaccinations.2.state', 'draft');

        $this->assertNotContains(
            $otherPetRecord,
            collect($response->json('vaccinations'))->pluck('id')->all(),
        );
    }

    public function test_treatment_response_preserves_missing_and_unknown_types(): void
    {
        $controller = app(AdminVaccinationController::class);
        foreach (['vaccine', 'medicine', null, '', 'unknown'] as $type) {
            $record = new \App\Models\VaccinationRecord(['treatment_type' => $type]);
            $this->assertSame($type, $controller->formatRecord($record)['treatment_type']);
        }
    }

    public function test_current_visit_treatments_exclude_other_cases_and_unlinked_history(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        DB::table('clinic_appointments')->insert([
            ['id' => 201, 'pet_id' => 101, 'appointment_reference' => 'CL-201'],
            ['id' => 202, 'pet_id' => 101, 'appointment_reference' => 'CL-202'],
        ]);
        $current = $this->insertVaccination(101, 'Current medicine', '2026-07-23', ['clinic_appointment_id' => 201, 'treatment_type' => 'medicine']);
        $this->insertVaccination(101, 'Earlier visit', '2026-07-23', ['clinic_appointment_id' => 202]);
        $this->insertVaccination(101, 'External record', '2020-01-01');
        $this->insertVaccination(102, 'Other pet', '2026-07-23', ['clinic_appointment_id' => 201]);
        $this->getJson('/api/admin/pets/101/vaccinations?clinic_appointment_id=201')->assertOk()
            ->assertJsonCount(1, 'vaccinations')->assertJsonPath('vaccinations.0.id', $current)
            ->assertJsonPath('vaccinations.0.clinic_appointment_id', 201);
        $this->getJson('/api/admin/pets/101/vaccinations')->assertOk()->assertJsonCount(3, 'vaccinations');
    }

    public function test_vaccination_under_the_wrong_pet_returns_the_generic_not_found_response(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $recordId = $this->insertVaccination(102, 'Rabies', '2026-07-23');

        $this->getJson("/api/admin/pets/101/vaccinations/{$recordId}")
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Vaccination record not found.',
            ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$recordId}", [
            'notes' => 'Must not update.',
        ])->assertNotFound();

        $this->assertDatabaseMissing('vaccination_records', [
            'id' => $recordId,
            'notes' => 'Must not update.',
        ]);
    }

    public function test_draft_snapshots_inventory_product_and_fefo_batch_without_changing_stock(): void
    {
        Carbon::setTestNow('2026-07-23 09:30:00');
        $this->authenticateAs('staff', 2);
        $this->insertUser(3, 'Ana', 'Santos', 'staff', 'veterinarian');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertRabiesStock(301);

        $response = $this->postJson('/api/admin/pets/101/vaccinations', [
            'clinic_appointment_id' => 201,
            'inventory_item_id' => 301,
            'next_due_date' => '2027-07-23',
            'dose_amount' => 1,
            'dose_unit' => 'mL',
            'route' => 'subcutaneous',
            'administration_site' => 'Right shoulder',
            'notes' => 'Initial dose.',
        ])
            ->assertCreated()
            ->assertJsonPath('vaccination.pet_id', 101)
            ->assertJsonPath('vaccination.recorded_by_user_id', 2)
            ->assertJsonPath('vaccination.vaccine_name', 'Rabies Vaccine')
            ->assertJsonPath('vaccination.administered_date', '2026-07-23')
            ->assertJsonPath('vaccination.dose_unit', 'mL')
            ->assertJsonPath('vaccination.product_name', null)
            ->assertJsonPath('vaccination.manufacturer', null)
            ->assertJsonPath('vaccination.batch_number', 'LOT-EARLY')
            ->assertJsonPath('vaccination.product_expiry_date', '2026-12-01')
            ->assertJsonPath('vaccination.clinic_appointment_reference', 'CL-201')
            ->assertJsonPath('vaccination.inventory_item_name', 'Rabies Vaccine')
            ->assertJsonPath('vaccination.state', 'draft');

        $recordId = $response->json('vaccination.id');
        $this->assertDatabaseHas('vaccination_records', [
            'id' => $recordId,
            'pet_id' => 101,
            'recorded_by_user_id' => 2,
            'published_at' => null,
            'published_by_user_id' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
        ]);
        $this->assertStockOnHand(301, '6');
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());

        $recordPayload = $response->json('vaccination');
        $this->assertArrayNotHasKey('password_hash', $recordPayload);
        $this->assertArrayNotHasKey('file_path', $recordPayload);
        $this->assertArrayNotHasKey('inventory_item', $recordPayload);
        $this->assertArrayNotHasKey('clinic_appointment', $recordPayload);
    }

    public function test_request_cannot_supply_server_managed_creation_or_update_fields(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertUser(3, 'Other', 'Admin', 'admin');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        $serverFields = [
            'pet_id' => 999,
            'recorded_by_user_id' => 3,
            'published_at' => '2026-07-23 10:00:00',
            'published_by_user_id' => 3,
            'voided_at' => '2026-07-23 11:00:00',
            'voided_by_user_id' => 3,
            'void_reason' => 'Injected.',
        ];

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301),
            ...$serverFields,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($serverFields));

        $recordId = $this->insertVaccination(101, 'Draft', '2026-07-23', [
            'recorded_by_user_id' => 2,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$recordId}", [
            'notes' => 'Injected update.',
            ...$serverFields,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($serverFields));

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $recordId,
            'pet_id' => 101,
            'recorded_by_user_id' => 2,
            'notes' => null,
        ]);
    }

    public function test_inventory_derived_fields_cannot_be_typed_by_staff(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        $typedFields = [
            'vaccine_name' => 'Rabies',
            'product_name' => 'Rabies Shield',
            'manufacturer' => 'Example Labs',
            'batch_number' => 'LOT-TYPED',
            'product_expiry_date' => '2027-12-01',
        ];

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301),
            ...$typedFields,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($typedFields));

        $draftId = $this->insertVaccination(101, 'Rabies Vaccine', '2026-07-23', [
            'inventory_item_id' => 301,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", $typedFields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($typedFields));

        $this->assertDatabaseCount('vaccination_records', 1);
    }

    public function test_invalid_pet_appointment_and_inventory_references_are_rejected(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertUser(4, 'Customer', 'Provider', 'customer');
        $this->insertUser(5, 'Grace', 'Reyes', 'staff', 'clinic_receptionist');
        $this->insertUser(6, 'Ben', 'Cruz', 'staff', 'veterinarian', false);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $this->insertAppointment(202, 102);
        $this->insertInventoryItem(301, 'Antibiotic', 'medicine', 20);
        $this->insertInventoryItem(302, 'Rabies Vaccine', 'vaccine', 0);
        $this->insertInventoryItem(303, 'Retired Vaccine', 'vaccine', 0, ['is_active' => false]);

        $valid = $this->validDraft(302);

        $this->postJson('/api/admin/pets/999/vaccinations', $valid)
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');

        foreach ([
            ['clinic_appointment_id', 999],
            ['clinic_appointment_id', 202],
            ['inventory_item_id', 999],
            ['inventory_item_id', 301],
            ['inventory_item_id', 303],
        ] as [$field, $value]) {
            $this->postJson('/api/admin/pets/101/vaccinations', [
                ...$valid,
                $field => $value,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('vaccination_records', 0);
        $this->assertStockOnHand(301, '20');
    }

    public function test_new_drafts_can_only_be_linked_to_an_ongoing_case(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);
        $this->insertAppointment(201, 101, 'completed');
        $this->insertAppointment(202, 101, 'cancelled');
        $this->insertAppointment(203, 101, 'waiting_to_arrive');
        $this->insertAppointment(204, 101, 'in_consultation');

        $valid = $this->validDraft(301);

        foreach ([201, 202, 203] as $closedCaseId) {
            $this->postJson('/api/admin/pets/101/vaccinations', [...$valid, 'clinic_appointment_id' => $closedCaseId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('clinic_appointment_id');
        }
        $this->assertDatabaseCount('vaccination_records', 0);

        $this->postJson('/api/admin/pets/101/vaccinations', [...$valid, 'clinic_appointment_id' => 204])
            ->assertCreated();
    }

    public function test_clinical_field_and_date_sequence_validation_is_enforced(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301),
            'next_due_date' => '2026-07-22',
            'dose_amount' => 0,
            'dose_unit' => str_repeat('m', 31),
            'route' => 'unsupported',
            'administration_site' => str_repeat('S', 101),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'next_due_date' => 'The next vaccination date cannot be before today.',
                'dose_amount',
                'dose_unit',
                'route',
                'administration_site',
            ]);

        $this->postJson('/api/admin/pets/101/vaccinations', [
            ...$this->validDraft(301),
            'dose_amount' => 100000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dose_amount']);

        $this->postJson('/api/admin/pets/101/vaccinations', ['inventory_item_id' => 301])
            ->assertCreated()->assertJsonPath('vaccination.dose_amount', null)->assertJsonPath('vaccination.dose_unit', null);
    }

    public function test_inventory_creation_uses_today_but_updating_preserves_existing_dates(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);

        foreach (['2026-07-01', '2026-08-01'] as $chosenDate) {
            $this->postJson('/api/admin/pets/101/vaccinations', [
                ...$this->validDraft(301),
                'administered_date' => $chosenDate,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('administered_date');
        }
        $this->assertDatabaseCount('vaccination_records', 0);

        $olderDraftId = $this->insertVaccination(101, 'Rabies Vaccine', '2026-07-20', [
            'inventory_item_id' => 301,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$olderDraftId}", $this->validDraft(301))
            ->assertOk()
            ->assertJsonPath('vaccination.administered_date', '2026-07-20');
    }

    public function test_staff_can_update_a_draft_but_not_move_it_or_edit_published_facts(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertPet(102, 'Zeus', 'dog');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 0);
        $draftId = $this->insertVaccination(101, 'Draft Name', '2026-07-23', [
            'inventory_item_id' => 301,
            'next_due_date' => '2026-08-23',
            'recorded_by_user_id' => 2,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'notes' => 'Updated while still a draft.',
        ])
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'draft');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'pet_id' => 102,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pet_id');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'next_due_date' => '2026-07-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('next_due_date');

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'dose_amount' => '',
        ])->assertOk();

        DB::table('vaccination_records')->where('id', $draftId)->update([
            'published_at' => now(),
            'published_by_user_id' => 2,
        ]);

        $this->patchJson("/api/admin/pets/101/vaccinations/{$draftId}", [
            'notes' => 'Silent published edit.',
        ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Published or voided vaccination records cannot be edited. Void an incorrect published record and create a corrected replacement.',
            );

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $draftId,
            'pet_id' => 101,
            'notes' => 'Updated while still a draft.',
        ]);
    }

    public function test_publishing_deducts_one_unit_from_the_fefo_batch_and_records_a_clinic_transaction(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('admin', 1);
        $this->insertUser(2, 'Ana', 'Santos', 'staff', 'veterinarian');
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertRabiesStock(301);
        $recordId = $this->insertFinalizableDraft(301, 201);

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/publish", [
            'published_at' => '2020-01-01 00:00:00',
            'published_by_user_id' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['published_at', 'published_by_user_id']);
        $this->assertStockOnHand(301, '6');

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/publish")
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'published')
            ->assertJsonPath('vaccination.batch_number', 'LOT-EARLY')
            ->assertJsonPath('vaccination.product_expiry_date', '2026-12-01')
            ->assertJsonPath('vaccination.published_by_user_id', 1)
            ->assertJsonPath('vaccination.published_at', '2026-07-23T14:15:00+08:00');

        $this->assertStockOnHand(301, '5');
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertDatabaseHas('inventory_transactions', [
            'item_id' => 301,
            'type' => 'stock_out',
            'quantity' => 1,
            'reason' => 'used',
            'reference_type' => 'clinic',
            'reference_id' => 201,
            'batch_number' => 'LOT-EARLY',
            'notes' => 'Vaccination · Mochi',
            'performed_by' => 1,
        ]);
        $this->assertStringStartsWith('2026-12-01', (string) DB::table('inventory_transactions')
            ->where('type', 'stock_out')
            ->value('expiry_date'));
        $this->assertSame('in_consultation', DB::table('clinic_appointments')->where('id', 201)->value('status'));

        $this->getJson('/api/inventory/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'stock_out')
            ->assertJsonPath('data.0.reason', 'used')
            ->assertJsonPath('data.0.batch_number', 'LOT-EARLY')
            ->assertJsonPath('data.0.source_label', 'Clinic · CL-201');

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/publish")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This vaccination record has already been published.');

        $this->postJson("/api/admin/pets/101/vaccinations/{$recordId}/void", [
            'void_reason' => 'Recorded on the wrong pet.',
        ])->assertOk();

        $this->assertStockOnHand(301, '5');
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_finishing_the_case_prepares_payment_with_the_deduction_and_rejects_closed_cases(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertAppointment(202, 101, 'completed');
        $this->insertRabiesStock(301);
        $ongoingCaseRecord = $this->insertFinalizableDraft(301, 201);
        $closedCaseRecord = $this->insertFinalizableDraft(301, 202);

        $this->postJson("/api/admin/pets/101/vaccinations/{$ongoingCaseRecord}/publish", ['finish_case' => true])
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'published');

        $this->assertDatabaseHas('clinic_appointments', [
            'id' => 201,
            'status' => 'for_payment',
            'queue_number' => 3,
            'consultation_finished_at' => '2026-07-23 14:15:00',
        ]);
        $this->assertStockOnHand(301, '5');

        $this->assertDatabaseHas('clinic_charges', [
            'clinic_appointment_id' => 201, 'vaccination_record_id' => $ongoingCaseRecord,
            'kind' => 'vaccine', 'amount' => 250,
        ]);

        $this->postJson("/api/admin/pets/101/vaccinations/{$closedCaseRecord}/publish", ['finish_case' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This case is already closed or is not eligible for vaccination.');

        $this->assertStockOnHand(301, '5');
        $this->assertDatabaseHas('vaccination_records', ['id' => $closedCaseRecord, 'published_at' => null]);
    }

    public function test_admin_can_amend_vaccinations_for_payment_and_refresh_the_charge_total(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('admin', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101, 'for_payment');
        $this->insertRabiesStock(301);
        $id = $this->insertFinalizableDraft(301, 201);
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['notes' => 'Confirmed administration'])
            ->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'for_payment', 'total_amount' => 250]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertConflict();
        $this->assertSame(1, DB::table('clinic_charges')->count());
        $this->assertStockOnHand(301, '5');
    }

    public function test_expired_or_incomplete_dose_inventory_drafts_do_not_deduct_and_unlinked_records_publish_without_stock(): void
    {
        Carbon::setTestNow('2026-07-23 14:15:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertInventoryItem(301, 'Rabies Vaccine', 'vaccine', 1);
        $this->insertStockIn(301, 1, 'LOT-OLD', '2026-07-01', '2026-06-01 08:00:00');
        $expiredOnlyRecord = $this->insertFinalizableDraft(301, null);
        $unlinkedRecord = $this->insertFinalizableDraft(null, null);

        $this->postJson("/api/admin/pets/101/vaccinations/{$expiredOnlyRecord}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('inventory_quantity');

        $this->postJson("/api/admin/pets/101/vaccinations/{$unlinkedRecord}/publish")
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'published');

        $doselessRecord = $this->insertFinalizableDraft(301, null);
        DB::table('vaccination_records')->where('id', $doselessRecord)->update(['dose_amount' => null]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$doselessRecord}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dose_amount');

        $this->assertStockOnHand(301, '1');
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertSame(2, DB::table('vaccination_records')->whereNull('published_at')->count());
    }

    public function test_publish_rejects_incomplete_and_voided_records(): void
    {
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $incompleteId = $this->insertVaccination(101, '', '2026-07-23');

        $this->postJson("/api/admin/pets/101/vaccinations/{$incompleteId}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The vaccination draft is not ready to publish.');

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $incompleteId,
            'published_at' => null,
        ]);

        $voidedId = $this->insertVaccination(101, 'FVRCP', '2026-07-22', [
            'published_at' => '2026-07-22 10:00:00',
            'published_by_user_id' => 2,
            'voided_at' => '2026-07-22 11:00:00',
            'voided_by_user_id' => 2,
            'void_reason' => 'Incorrect batch.',
        ]);

        $this->postJson("/api/admin/pets/101/vaccinations/{$voidedId}/publish")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A voided vaccination record cannot be published again.');
    }

    public function test_published_record_can_be_voided_but_draft_or_empty_reason_cannot(): void
    {
        Carbon::setTestNow('2026-07-23 15:30:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $publishedId = $this->insertVaccination(101, 'Rabies', '2026-07-23', [
            'published_at' => '2026-07-23 14:00:00',
            'published_by_user_id' => 2,
        ]);
        $draftId = $this->insertVaccination(101, 'FVRCP', '2026-07-23');

        $this->postJson("/api/admin/pets/101/vaccinations/{$publishedId}/void", [
            'void_reason' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('void_reason');

        $this->postJson("/api/admin/pets/101/vaccinations/{$draftId}/void", [
            'void_reason' => 'Draft correction.',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Draft vaccination records cannot use the published-record void workflow.');

        $this->postJson("/api/admin/pets/101/vaccinations/{$publishedId}/void", [
            'void_reason' => 'Injected audit values.',
            'voided_at' => '2020-01-01 00:00:00',
            'voided_by_user_id' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['voided_at', 'voided_by_user_id']);

        $this->postJson("/api/admin/pets/101/vaccinations/{$publishedId}/void", [
            'void_reason' => 'Wrong product was recorded.',
        ])
            ->assertOk()
            ->assertJsonPath('vaccination.state', 'voided')
            ->assertJsonPath('vaccination.voided_by_user_id', 2)
            ->assertJsonPath('vaccination.voided_at', '2026-07-23T15:30:00+08:00')
            ->assertJsonPath('vaccination.void_reason', 'Wrong product was recorded.');

        $this->assertDatabaseCount('vaccination_records', 2);
        $this->assertDatabaseHas('vaccination_records', [
            'id' => $publishedId,
            'voided_by_user_id' => 2,
            'voided_at' => '2026-07-23 15:30:00',
            'void_reason' => 'Wrong product was recorded.',
        ]);
    }

    public function test_historical_and_external_records_preserve_facts_without_consuming_current_stock(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertRabiesStock(301);
        foreach (['2026-06-01', '2026-07-23'] as $date) {
            $id = $this->postJson('/api/admin/pets/101/vaccinations', [
                'inventory_item_id' => null, 'vaccine_name' => 'External Rabies',
                'administered_date' => $date, 'product_name' => 'Original product', 'manufacturer' => 'Original manufacturer',
                'batch_number' => 'ORIGINAL', 'product_expiry_date' => '2026-08-01',
            ])->assertCreated()->json('vaccination.id');
            $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['notes' => 'Documented later.'])
                ->assertOk()->assertJsonPath('vaccination.administered_date', $date);
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => false])
                ->assertOk()->assertJsonPath('vaccination.administered_date', $date)
                ->assertJsonPath('vaccination.batch_number', 'ORIGINAL')
                ->assertJsonPath('vaccination.manufacturer', 'Original manufacturer');
        }
        $this->assertStockOnHand(301, '6');
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_old_inventory_drafts_require_explicit_historical_documentation_and_keep_the_date(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertRabiesStock(301);
        $id = $this->insertFinalizableDraft(301, null);
        DB::table('vaccination_records')->where('id', $id)->update(['administered_date' => '2026-07-20', 'batch_number' => 'ORIGINAL']);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('administered_date');
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['inventory_item_id' => null])
            ->assertOk()->assertJsonPath('vaccination.administered_date', '2026-07-20');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => false])
            ->assertOk()->assertJsonPath('vaccination.administered_date', '2026-07-20')
            ->assertJsonPath('vaccination.batch_number', 'ORIGINAL');
        $this->assertStockOnHand(301, '6');
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_vial_bottle_and_box_vaccines_finalize_without_a_single_dose_policy(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertRabiesStock(301);
        foreach (['vial', 'bottle', 'box'] as $unit) {
            DB::table('inventory_items')->where('item_id', 301)->update(['unit' => $unit]);
            $id = $this->insertFinalizableDraft(301, null);
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        }
        $this->assertStockOnHand(301, '3');
        $this->assertSame(3, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_publication_rechecks_product_and_case_eligibility_without_finishing(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertRabiesStock(301);
        $id = $this->insertFinalizableDraft(301, 201);
        foreach ([['is_active' => false], ['is_active' => true, 'category' => 'medicine']] as $changes) {
            DB::table('inventory_items')->where('item_id', 301)->update($changes);
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")
                ->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
        }
        DB::table('inventory_items')->where('item_id', 301)->update(['category' => 'vaccine']);
        foreach (['completed', 'cancelled', 'waiting_to_arrive'] as $status) {
            DB::table('clinic_appointments')->where('id', 201)->update(['status' => $status]);
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertStatus(409);
        }
        $this->assertStockOnHand(301, '6');
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_lost_inventory_link_cannot_change_a_requested_stock_publication_into_documentation(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertRabiesStock(301);
        $id = $this->insertFinalizableDraft(301, null);
        DB::table('inventory_items')->where('item_id', 301)->delete();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
    }

    public function test_consultation_completion_requires_vitals_and_then_publishes_atomically(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        DB::table('clinic_appointments')->where('id', 201)->update(['case_type' => 'consultation']);
        $this->insertRabiesStock(301);
        $id = $this->insertFinalizableDraft(301, 201);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['finish_case' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(\App\Models\ClinicAppointment::COMPLETION_VITAL_FIELDS);
        $this->assertStockOnHand(301, '6');
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 201, 'weight_kg' => 4.5,
            'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 201, 'primary_veterinarian_id' => 2]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['finish_case' => true])->assertOk();
        $this->assertStockOnHand(301, '5');
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'for_payment']);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertStatus(409);
        $this->assertStockOnHand(301, '5');
    }

    public function test_unlinked_records_cannot_finish_cases_and_historical_dates_are_validated(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $id = $this->insertFinalizableDraft(null, null);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['finish_case' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('finish_case');
        $this->postJson('/api/admin/pets/101/vaccinations', ['vaccine_name' => 'External', 'administered_date' => '2026-07-24'])
            ->assertUnprocessable()->assertJsonValidationErrors('administered_date');
        $this->postJson('/api/admin/pets/101/vaccinations', ['vaccine_name' => 'External', 'administered_date' => '2026-07-01', 'next_due_date' => '2026-06-30'])
            ->assertUnprocessable()->assertJsonValidationErrors('next_due_date');
    }

    public function test_exhausted_stock_is_rejected_and_quantity_can_span_usable_fefo_batches(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertRabiesStock(301);
        $id = $this->insertFinalizableDraft(301, null);
        DB::table('inventory_items')->where('item_id', 301)->update(['quantity_on_hand' => 0]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors('inventory_quantity');
        DB::table('inventory_items')->where('item_id', 301)->update(['quantity_on_hand' => 6]);
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['inventory_quantity' => 3])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $balance = app(\App\Services\InventoryBatchBalanceService::class)->forItem(301);
        $this->assertSame(2.0, $balance['unexpired_quantity']);
        $this->assertSame(1.0, $balance['expired_quantity']);
        $this->assertStockOnHand(301, '3');
    }

    public function test_publication_and_historical_edits_do_not_depend_on_a_treatment_provider(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertRabiesStock(301);
        $id = $this->insertFinalizableDraft(301, null);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk()
            ->assertJsonMissingPath('vaccination.administered_by_user_id')
            ->assertJsonMissingPath('vaccination.administered_by_name');
        $this->assertFalse(Schema::hasColumn('vaccination_records', 'administered_by_user_id'));
        $this->assertFalse(Schema::hasColumn('inventory_items', 'dose_unit'));
    }

    public function test_every_staff_vaccination_route_has_authentication_and_role_middleware(): void
    {
        $expected = [
            ['GET', 'api/admin/vaccination-options'],
            ['GET', 'api/admin/pets/{petId}/vaccinations'],
            ['POST', 'api/admin/pets/{petId}/vaccinations'],
            ['GET', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}'],
            ['PATCH', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}'],
            ['DELETE', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}'],
            ['POST', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}/publish'],
            ['POST', 'api/admin/pets/{petId}/vaccinations/{vaccinationId}/void'],
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($expected as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected vaccination route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        }

        $controllerRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with(
                $route->getActionName(),
                AdminVaccinationController::class.'@',
            ),
        );

        $this->assertCount(8, $controllerRoutes);

        $clientRoute = $routes->first(
            fn (RoutingRoute $route) => $route->uri() === 'api/pets/{petId}/vaccinations'
                && in_array('GET', $route->methods(), true),
        );

        $this->assertNotNull($clientRoute);
        $this->assertSame(
            PetVaccinationController::class.'@index',
            $clientRoute->getActionName(),
        );
        $this->assertContains('auth:sanctum', $clientRoute->gatherMiddleware());
        $this->assertNotContains('role:admin,staff', $clientRoute->gatherMiddleware());
    }

    private function medicineTreatment(array $changes = []): array
    {
        return array_replace([
            'treatment_type' => 'medicine', 'inventory_item_id' => 301, 'clinic_appointment_id' => 201,
            'dose_amount' => 0.5, 'dose_unit' => 'mL', 'route' => 'oral',
            'inventory_quantity' => 1,
            'expected_price' => 250, 'expected_weight_kg' => null,
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $changes);
    }

    private function medicineStock(string $expiry = '2027-07-23'): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('staff', 2);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertAppointment(201, 101);
        $this->insertInventoryItem(301, 'Clinic Medicine', 'medicine', 2, [
            'unit' => 'bottle', 'strength_concentration' => '10 mg/mL',
        ]);
        $this->insertStockIn(301, 2, 'MED-1', $expiry, '2026-06-01 08:00:00');
    }

    public function test_checked_in_patient_cannot_record_or_publish_treatment_until_consultation_starts(): void
    {
        $this->medicineStock();
        DB::table('clinic_appointments')->where('id', 201)->update(['status' => 'checked_in']);
        $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertUnprocessable();
        $this->assertDatabaseCount('vaccination_records', 0);
        DB::table('clinic_appointments')->where('id', 201)->update(['status' => 'in_consultation']);
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        DB::table('clinic_appointments')->where('id', 201)->update(['status' => 'checked_in']);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertConflict();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        DB::table('clinic_appointments')->where('id', 201)->update(['status' => 'in_consultation']);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_medicine_treatment_drafts_and_retries_publish_one_charge_and_stock_out(): void
    {
        $this->medicineStock();
        $payload = $this->medicineTreatment();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertCreated()
            ->assertJsonPath('vaccination.treatment_type', 'medicine')
            ->assertJsonPath('vaccination.treatment_name', 'Clinic Medicine')
            ->assertJsonPath('vaccination.strength_concentration', '10 mg/mL')->json('vaccination.id');
        $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertOk()->assertJsonPath('vaccination.id', $id);
        $this->assertDatabaseCount('vaccination_records', 1);
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", [...$payload, 'inventory_quantity' => 1])->assertOk();
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['notes' => 'Updated twice'])->assertOk();
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => true])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => true])->assertConflict();
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", $payload)->assertConflict();
        $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertOk()->assertJsonPath('vaccination.id', $id);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertDatabaseHas('clinic_charges', ['kind' => 'medicine', 'vaccination_record_id' => $id, 'amount' => 250]);
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertEquals(1, DB::table('inventory_items')->where('item_id', 301)->value('quantity_on_hand'));
    }

    public function test_medicine_drafts_are_permissive_but_finalize_requires_quantity_case_and_price(): void
    {
        $this->medicineStock();
        foreach (['inventory_quantity' => null, 'clinic_appointment_id' => null, 'expected_price' => null] as $field => $value) {
            $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment([$field => $value]))
                ->assertCreated()->json('vaccination.id');
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable();
        }
        $this->insertInventoryItem(302, 'Vaccine', 'vaccine', 2);
        $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment(['inventory_item_id' => 302]))
            ->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_weight_based_medicine_uses_saved_vitals_and_stale_quotes_roll_back(): void
    {
        $this->medicineStock();
        DB::table('inventory_items')->where('item_id', 301)->update(['pricing_method' => 'weight_based', 'weight_pricing_mode' => 'ranges']);
        DB::table('inventory_item_weight_prices')->insert(['item_id' => 301, 'min_weight_kg' => 1, 'max_weight_kg' => 5, 'price' => 400]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 201, 'weight_kg' => 4]);
        $payload = $this->medicineTreatment(['expected_price' => 400, 'expected_weight_kg' => 4]);
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertCreated()->json('vaccination.id');
        DB::table('clinic_vitals')->where('clinic_appointment_id', 201)->update(['weight_kg' => 4.5]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['expected_weight_kg' => 4.5])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $id, 'amount' => 400, 'pet_weight_kg' => 4.5, 'min_weight_kg' => 1, 'max_weight_kg' => 5]);
    }

    public function test_expired_and_insufficient_medicine_stock_roll_back_charges_and_publication(): void
    {
        $this->medicineStock('2026-07-01');
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable();
        DB::table('inventory_transactions')->update(['expiry_date' => '2027-07-23']);
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['inventory_quantity' => 3])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
        $this->assertEquals(2, DB::table('inventory_items')->where('item_id', 301)->value('quantity_on_hand'));
    }

    public function test_historical_medicine_has_no_charge_or_stock_use_and_history_is_chronological(): void
    {
        $this->medicineStock();
        $payload = $this->medicineTreatment(['inventory_item_id' => null, 'vaccine_name' => 'External medicine',
            'administered_date' => '2020-01-02', ]);
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertCreated()
            ->assertJsonPath('vaccination.inventory_quantity', null)->assertJsonPath('vaccination.expected_price', null)->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => false])->assertOk();
        $vaccineId = $this->insertVaccination(101, 'Rabies', '2021-01-01');
        $this->getJson('/api/admin/pets/101/vaccinations')->assertOk()->assertJsonPath('vaccinations.0.id', $vaccineId)
            ->assertJsonPath('vaccinations.0.treatment_type', 'vaccine')->assertJsonPath('vaccinations.1.id', $id);
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertSame(0, \App\Models\VaccinationRecord::visibleToClients()->whereKey($id)->count());
    }

    public function test_medicine_publication_and_case_completion_are_atomic(): void
    {
        $this->medicineStock();
        DB::table('clinic_appointments')->where('id', 201)->update(['case_type' => 'consultation']);
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['finish_case' => true])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'in_consultation']);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 201, 'weight_kg' => 4,
            'temperature_c' => 38.5, 'heart_rate_bpm' => 100, 'respiratory_rate_bpm' => 24]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 201, 'primary_veterinarian_id' => 2]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['finish_case' => true])->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'for_payment', 'total_amount' => 250]);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_medicine_draft_can_be_converted_to_external_without_stock_or_charges(): void
    {
        $this->medicineStock();
        $payload = $this->medicineTreatment();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertCreated()->json('vaccination.id');
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", [
            'inventory_item_id' => null, 'vaccine_name' => 'External medicine', 'administered_date' => '2020-01-01',
            ])->assertOk()->assertJsonPath('vaccination.inventory_quantity', null)->assertJsonPath('vaccination.expected_price', null);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['consume_inventory' => false])->assertOk();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertEquals(2, DB::table('inventory_items')->where('item_id', 301)->value('quantity_on_hand'));
    }

    public function test_vaccine_stock_quantity_is_independent_of_draft_or_changed_clinical_dose(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $this->authenticateAs('admin', 1);
        $this->insertPet(101, 'Mochi', 'cat');
        $this->insertRabiesStock(301);
        foreach ([null, 2.5] as $dose) {
            $id = $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => 'vaccine', 'inventory_item_id' => 301, 'inventory_quantity' => 1,
                'dose_amount' => $dose, 'dose_unit' => $dose === null ? null : 'mL',
            ])->assertCreated()->json('vaccination.id');
            if ($dose !== null) {
                $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_amount' => 7.5])
                    ->assertOk()->assertJsonPath('vaccination.inventory_quantity', '1.00');
            } else {
                $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")
                    ->assertUnprocessable()->assertJsonValidationErrors(['dose_amount', 'dose_unit']);
                $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_amount' => 0.5, 'dose_unit' => 'mL'])->assertOk();
            }
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        }
        $this->assertStockOnHand(301, '4');
        $this->assertEquals([1, 1], DB::table('inventory_transactions')->where('type', 'stock_out')->pluck('quantity')->all());
        $this->assertDatabaseHas('vaccination_records', ['dose_amount' => 7.5, 'dose_unit' => 'mL']);
    }

    public function test_bottle_medicine_deducts_one_unit_with_documented_dose_and_unchanged_flat_price(): void
    {
        $this->medicineStock();
        foreach ([null, 2.5] as $dose) {
            $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment([
                'inventory_quantity' => 1, 'dose_amount' => $dose, 'dose_unit' => $dose === null ? null : 'mL',
            ]))->assertCreated()->json('vaccination.id');
            if ($dose !== null) $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_amount' => 9])->assertOk();
            else {
                $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")
                    ->assertUnprocessable()->assertJsonValidationErrors(['dose_amount', 'dose_unit']);
                $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_amount' => 0.5, 'dose_unit' => 'mL'])->assertOk();
            }
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk()
                ->assertJsonPath('vaccination.batch_number', 'MED-1')
                ->assertJsonPath('vaccination.product_expiry_date', '2027-07-23');
            $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $id, 'quantity' => 1, 'amount' => 250]);
        }
        $this->assertStockOnHand(301, '0');
        $this->assertEquals([1, 1], DB::table('inventory_transactions')->where('type', 'stock_out')->pluck('quantity')->all());
        $this->assertEquals(500, DB::table('clinic_appointments')->where('id', 201)->value('total_amount'));
    }

    public function test_both_treatment_types_reject_fractional_quantity_and_incomplete_dose_pairs(): void
    {
        $this->medicineStock();
        $this->insertRabiesStock(302);
        foreach (['medicine' => 301, 'vaccine' => 302] as $type => $itemId) {
            foreach ([0, 0.25, 0.5, 1.25] as $quantity) {
                $this->postJson('/api/admin/pets/101/vaccinations', [
                    'treatment_type' => $type, 'inventory_item_id' => $itemId, 'inventory_quantity' => $quantity,
                ])->assertUnprocessable()->assertJsonValidationErrors('inventory_quantity');
            }
            foreach ([['dose_amount' => 1], ['dose_unit' => 'mL']] as $partialDose) {
                $this->postJson('/api/admin/pets/101/vaccinations', [
                    'treatment_type' => $type, 'inventory_item_id' => $itemId, ...$partialDose,
                ])->assertUnprocessable()->assertJsonValidationErrors(isset($partialDose['dose_amount']) ? 'dose_unit' : 'dose_amount');
            }
        }
        $this->assertDatabaseCount('vaccination_records', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_medicine_search_uses_names_and_only_unexpired_availability_including_disabled_products(): void
    {
        $this->medicineStock();
        $this->insertStockIn(301, 8, 'EXPIRED', '2026-07-01', '2026-06-02');
        DB::table('inventory_items')->where('item_id', 301)->update(['quantity_on_hand' => 10, 'reorder_level' => 3]);
        $this->insertInventoryItem(302, 'Expired Medicine', 'medicine', 8);
        $this->insertStockIn(302, 8, 'OLD', '2026-07-01', '2026-06-01');
        $this->insertInventoryItem(303, 'Empty Medicine', 'medicine', 0);
        $this->insertInventoryItem(304, 'Retired Medicine', 'medicine', 0, ['is_active' => false]);
        $items = collect($this->getJson('/api/admin/clinic-medicine-options?q=Medicine')->assertOk()->json('data'))->keyBy('item_id');
        $this->assertCount(4, $items);
        $this->assertSame(2, $items[301]['available_quantity']);
        $this->assertSame('low_stock', $items[301]['stock_status']);
        $this->assertSame('expired', $items[302]['stock_status']);
        $this->assertSame(0, $items[302]['available_quantity']);
        $this->assertSame('out_of_stock', $items[303]['stock_status']);
        $this->assertFalse($items[304]['is_active']);
        $this->assertArrayNotHasKey('dose_unit', $items[301]);
    }

    public function test_incomplete_drafts_save_but_cannot_finalize_and_partial_dose_updates_preserve_the_pair(): void
    {
        $this->medicineStock();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', ['treatment_type' => 'medicine'])
            ->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())
            ->assertCreated()->json('vaccination.id');
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_unit' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('dose_unit');
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_amount' => null, 'dose_unit' => null])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors(['dose_amount', 'dose_unit']);
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_amount' => '0.5', 'dose_unit' => 'mL'])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
    }

    public function test_cleanup_migration_preserves_clinical_dose_history_legacy_stock_and_medical_record_veterinarian(): void
    {
        $this->medicineStock();
        $migration = require database_path('migrations/2026_10_12_000004_separate_treatment_clinical_details_from_inventory.php');
        $migration->down();
        DB::table('inventory_items')->where('item_id', 301)->update(['unit' => 'mL', 'quantity_on_hand' => 3.5, 'dose_unit' => 'mL']);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 201, 'primary_veterinarian_id' => 2]);
        $id = $this->insertVaccination(101, 'Historical medicine', '2020-01-01', [
            'dose_amount' => 2.5, 'dose_unit' => 'mL', 'administered_by_user_id' => 2, 'administered_by_name' => 'Old field',
        ]);
        $migration->up();
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'dose_amount' => 2.5, 'dose_unit' => 'mL']);
        $this->assertDatabaseHas('inventory_items', ['item_id' => 301, 'unit' => 'mL', 'quantity_on_hand' => 3.5]);
        $this->assertDatabaseHas('clinic_records', ['clinic_appointment_id' => 201, 'primary_veterinarian_id' => 2]);
        $this->assertFalse(Schema::hasColumn('vaccination_records', 'administered_by_name'));
        $this->assertFalse(Schema::hasColumn('inventory_items', 'dose_unit'));
    }

    public function test_legacy_measured_stock_is_readable_but_never_reinterpreted_as_physical_treatment_units(): void
    {
        $this->medicineStock();
        DB::table('inventory_items')->where('item_id', 301)->update(['unit' => 'ml']);
        $this->getJson('/api/admin/clinic-medicine-options?q=Medicine')->assertOk()
            ->assertJsonPath('data.0.unit', 'ml')->assertJsonPath('data.0.quantity_on_hand', 2)
            ->assertJsonPath('data.0.available_quantity', 0)->assertJsonPath('data.0.stock_status', 'legacy_unit');
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
        $this->assertStockOnHand(301, '2');
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_weight_based_medicine_never_falls_back_to_profile_weight_or_guesses_an_uncovered_band(): void
    {
        $this->medicineStock();
        Schema::table('pets', fn (Blueprint $table) => $table->decimal('weight', 5, 2)->nullable());
        DB::table('pets')->update(['weight' => 4]);
        DB::table('inventory_items')->where('item_id', 301)->update(['pricing_method' => 'weight_based', 'weight_pricing_mode' => 'ranges']);
        DB::table('inventory_item_weight_prices')->insert(['item_id' => 301, 'min_weight_kg' => 1, 'max_weight_kg' => 5, 'price' => 400]);
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment(['expected_price' => 400, 'expected_weight_kg' => 4]))->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 201, 'weight_kg' => 8]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
        DB::table('inventory_item_weight_prices')->insert(['item_id' => 301, 'min_weight_kg' => 6, 'max_weight_kg' => 10, 'price' => 700]);
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['expected_price' => 700, 'expected_weight_kg' => 8])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $id, 'pet_weight_kg' => 8, 'amount' => 700]);
    }

    public function test_inventory_price_changes_require_review_and_published_snapshots_are_read_only_for_all_billing_roles(): void
    {
        $this->medicineStock();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment(['inventory_quantity' => 2]))->assertCreated()->json('vaccination.id');
        DB::table('inventory_items')->where('item_id', 301)->update(['selling_price' => 300]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertUnprocessable()->assertJsonValidationErrors('medicines');
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertStockOnHand(301, '2');
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['expected_price' => 300])->assertOk();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $id, 'quantity' => 1, 'unit_price' => 300, 'amount' => 300]);
        $this->assertDatabaseHas('inventory_transactions', ['vaccination_record_id' => $id, 'quantity' => 2, 'selling_price_at_time' => 300]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'total_amount' => 300]);
        $charge = DB::table('clinic_charges')->first();
        DB::table('inventory_items')->where('item_id', 301)->update(['selling_price' => 900, 'is_active' => false]);
        foreach (['veterinarian', 'clinic_receptionist', 'admin'] as $subrole) {
            $user = new User;
            $user->forceFill(['user_id' => 2, 'role' => $subrole === 'admin' ? 'admin' : 'staff', 'staff_type' => 'clinic', 'staff_subrole' => $subrole]);
            Sanctum::actingAs($user);
            foreach ([['quantity' => 2], ['unit_price' => 0], ['unit_price' => 900]] as $edit) {
                $response = $this->patchJson("/api/admin/clinic-cases/201/charges/{$charge->id}", $edit);
                if ($subrole === 'clinic_receptionist') $response->assertForbidden();
                else $response->assertUnprocessable()->assertJsonValidationErrors('charges');
            }
        }
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertConflict();
        $this->assertEquals($charge, DB::table('clinic_charges')->first());
        $this->assertSame(1, DB::table('inventory_transactions')->where('vaccination_record_id', $id)->count());
    }

    public function test_vaccine_void_removes_its_charge_and_preserves_stock_usage_and_audit(): void
    {
        $this->medicineStock(); $this->insertRabiesStock(302);
        $id = $this->insertFinalizableDraft(302, 201);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'in_consultation', 'total_amount' => 250]);
        $this->assertDatabaseHas('inventory_transactions', ['vaccination_record_id' => $id, 'quantity' => 1, 'batch_number' => 'LOT-EARLY']);
        $movement = DB::table('inventory_transactions')->where('vaccination_record_id', $id)->first();
        DB::table('inventory_items')->where('item_id', 302)->update(['selling_price' => 800]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/void", ['void_reason' => 'Audit correction'])->assertOk();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'void_reason' => 'Audit correction']);
        $this->assertEquals($movement, DB::table('inventory_transactions')->where('vaccination_record_id', $id)->first());
        $this->assertStockOnHand(302, '5');
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'total_amount' => 0]);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertConflict();
    }

    public function test_mixed_confinement_care_reuses_treatment_stock_services_and_billing_on_the_same_case(): void
    {
        $this->medicineStock(); $this->insertRabiesStock(302);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 201, 'weight_kg' => 4.5, 'temperature_c' => 38.5, 'heart_rate_bpm' => 90, 'respiratory_rate_bpm' => 20]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 201, 'primary_veterinarian_id' => 2]);
        foreach (['2026_10_13_000001_create_clinic_services_table.php', '2026_10_13_000002_create_clinic_case_services_table.php',
            '2026_10_13_000004_create_clinic_confinements.php', '2026_10_14_000001_remove_clinic_service_type.php', '2026_10_14_000002_configure_confinement_charges.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        $service = \App\Models\ClinicService::create(['name' => 'Inpatient care', 'category_id' => DB::table('clinic_service_categories')->where('name', 'Confinement')->value('id'),
            'identity_key' => hash('sha256', 'inpatient'), 'pricing_method' => 'tiered', 'tier_basis' => 'weight_kg', 'charge_behavior' => 'base_confinement',
            'tiers' => array_map(fn ($size, $cutoff) => ['size' => $size, 'max_weight_kg' => $cutoff, 'price' => 500],
                ['small', 'medium', 'large', 'extra_large'], [10, 25, 40, null]), 'billing_unit' => 'day', 'is_active' => true]);
        $this->postJson('/api/admin/clinic-cases/201/confinement', ['reason' => 'Observe', 'request_key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish", ['finish_case' => true])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('clinic_charges', 0); $this->assertStockOnHand(301, '2');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $vaccineId = $this->insertFinalizableDraft(302, 201);
        $this->postJson("/api/admin/pets/101/vaccinations/{$vaccineId}/publish")->assertOk();
        $this->postJson('/api/admin/clinic-cases/201/confinement/observations', ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'notes' => 'Stable'])->assertCreated();
        $ordinary = \App\Models\ClinicService::create(['name' => 'Sample service', 'category_id' => 2, 'identity_key' => hash('sha256', 'sample-service'), 'pricing_method' => 'fixed', 'price' => 500, 'is_active' => true]);
        $this->postJson('/api/admin/clinic-cases/201/services', ['clinic_service_id' => $ordinary->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'in_consultation', 'total_amount' => 1000]);
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->postJson('/api/admin/clinic-cases/201/confinement/discharge')->assertOk()->assertJsonPath('data.case_service.quantity', '2.00');
        $this->postJson('/api/admin/clinic-cases/201/confinement/discharge')->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'for_payment', 'total_amount' => 2000]);
        $this->assertDatabaseCount('clinic_appointments', 1); $this->assertDatabaseCount('clinic_charges', 4);
        $this->assertSame(2, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    public function test_triamcinolone_uses_case_weight_and_void_replacement_keeps_charge_ownership(): void
    {
        $this->medicineStock();
        (require database_path('migrations/2026_10_13_000001_create_clinic_services_table.php'))->up();
        (require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php'))->up();
        (require database_path('migrations/2026_10_14_000001_remove_clinic_service_type.php'))->up();
        DB::table('inventory_items')->where('item_id', 301)->update([
            'item_name' => 'Triamcinolone Injectable', 'pricing_method' => 'weight_based', 'weight_pricing_mode' => 'ranges',
        ]);
        DB::table('inventory_item_weight_prices')->insert(['item_id' => 301, 'min_weight_kg' => 1, 'max_weight_kg' => 5, 'price' => 480]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 201, 'weight_kg' => 4.1]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 201, 'primary_veterinarian_id' => 2]);
        $medical = DB::table('clinic_records')->first();
        $payload = $this->medicineTreatment(['expected_price' => 480, 'expected_weight_kg' => 4.1]);
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->postJson('/api/admin/pets/101/vaccinations', $payload)->assertOk()->assertJsonPath('vaccination.id', $id);
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertConflict();
        $this->assertDatabaseCount('vaccination_records', 1);
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $id, 'pet_weight_kg' => 4.1, 'amount' => 480]);
        $this->getJson('/api/admin/clinic-cases/201/charges?scope=treatment')->assertOk()
            ->assertJsonPath('charges.data.0.description', 'Triamcinolone Injectable')->assertJsonPath('total_amount', 480);
        $movement = DB::table('inventory_transactions')->where('vaccination_record_id', $id)->first();
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/void", ['void_reason' => 'Correct administration record'])->assertOk();
        $this->assertEquals($movement, DB::table('inventory_transactions')->where('vaccination_record_id', $id)->first());
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->getJson('/api/admin/clinic-cases/201/charges?scope=treatment')->assertOk()->assertJsonPath('total_amount', 0);
        $replacement = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment([
            'expected_price' => 480, 'expected_weight_kg' => 4.1,
        ]))->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$replacement}/publish")->assertOk();
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $replacement, 'amount' => 480]);
        $this->assertEquals($medical, DB::table('clinic_records')->first());
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertStockOnHand(301, '0');
        (require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php'))->down();
        (require database_path('migrations/2026_10_13_000001_create_clinic_services_table.php'))->down();
    }

    public function test_case_linked_treatment_cannot_be_voided_after_payment_archive_or_lock(): void
    {
        $this->medicineStock();
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->boolean('paid')->default(false); $table->timestamp('archived_at')->nullable();
        });
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $charge = DB::table('clinic_charges')->first();
        foreach ([['paid' => true], ['archived_at' => now()], ['status' => 'completed'], ['status' => 'cancelled'], ['status' => 'for_payment']] as $lock) {
            DB::table('clinic_appointments')->where('id', 201)->update(['paid' => false, 'archived_at' => null, 'status' => 'in_consultation', ...$lock]);
            $this->postJson("/api/admin/pets/101/vaccinations/{$id}/void", ['void_reason' => 'Correction'])->assertConflict();
            $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'voided_at' => null]);
            $this->assertEquals($charge, DB::table('clinic_charges')->first());
        }
    }

    public function test_treatment_void_rolls_back_when_the_charge_total_cannot_be_updated(): void
    {
        $this->medicineStock();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $this->mock(\App\Services\ClinicBillingService::class)->shouldReceive('updateTotal')->once()
            ->andThrow(\Illuminate\Validation\ValidationException::withMessages(['charges' => 'Unable to update total.']));
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/void", ['void_reason' => 'Correction'])->assertUnprocessable();
        $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'voided_at' => null]);
        $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $id, 'amount' => 250]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'total_amount' => 250]);
    }

    public function test_database_prevents_a_second_stock_movement_for_a_published_treatment(): void
    {
        $this->medicineStock();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->postJson("/api/admin/pets/101/vaccinations/{$id}/publish")->assertOk();
        $attributes = (array) DB::table('inventory_transactions')->where('vaccination_record_id', $id)->first(); unset($attributes['transaction_id']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_transactions')->insert($attributes);
    }

    public function test_case_record_draft_and_finalization_are_atomic_and_successful_retries_are_idempotent(): void
    {
        $this->medicineStock();
        DB::table('clinic_appointments')->where('id', 201)->update(['case_type' => 'consultation']);
        Schema::table('clinic_records', function (Blueprint $table) {
            foreach (['chief_complaint', 'diagnosis', 'findings', 'treatment_given', 'follow_up_notes', 'vet_notes',
                'relevant_history', 'reported_signs', 'physical_exam_findings', 'differential_diagnoses', 'working_diagnosis'] as $field) {
                $table->text($field)->nullable();
            }
            $table->date('follow_up_date')->nullable();
            $table->string('primary_veterinarian_name')->nullable();
            $table->unsignedInteger('recorded_by_user_id')->nullable();
            $table->string('recorded_by_name')->nullable();
            foreach (['additional_veterinarians', 'diagnostics', 'prescriptions'] as $field) $table->json($field)->nullable();
            $table->timestamps();
        });
        Schema::table('clinic_vitals', function (Blueprint $table) {
            $table->unsignedTinyInteger('body_condition_score')->nullable();
            $table->timestamps();
        });
        Schema::create('clinic_attachments', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_record_id');
        });
        try {
            $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment(['inventory_quantity' => 3]))
                ->assertCreated()->json('vaccination.id');
            $vitals = ['weight_kg' => 4, 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20];
            $endpoint = '/api/admin/clinic-appointments/201/record';
            $this->postJson($endpoint, [...$vitals, 'working_diagnosis' => 'Unfinished', 'finish_case' => false])->assertOk();
            $this->assertDatabaseCount('clinic_charges', 0);
            $this->assertDatabaseHas('vaccination_records', ['id' => $id, 'published_at' => null]);
            $this->postJson($endpoint, ['finish_case' => true])->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
            $payload = [...$vitals, 'weight_kg' => 5, 'working_diagnosis' => 'Final assessment', 'finish_case' => true];
            $this->postJson($endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors('treatments.'.$id.'.inventory_quantity');
            $this->assertDatabaseHas('clinic_records', ['clinic_appointment_id' => 201, 'working_diagnosis' => 'Unfinished']);
            $this->assertDatabaseHas('clinic_vitals', ['clinic_appointment_id' => 201, 'weight_kg' => 4]);
            $this->assertStockOnHand(301, '2');
            $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['inventory_quantity' => 1])->assertOk();
            $this->postJson($endpoint, $payload)->assertOk();
            $this->postJson($endpoint, $payload)->assertOk();
            $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'for_payment']);
            $this->assertDatabaseHas('clinic_records', ['clinic_appointment_id' => 201, 'working_diagnosis' => 'Final assessment']);
            $this->assertDatabaseCount('clinic_charges', 1);
            $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
            $this->assertStockOnHand(301, '1');
        } finally {
            Schema::dropIfExists('clinic_attachments');
        }
    }

    public function test_visit_finalization_commits_multiple_treatments_and_skips_already_committed_entries(): void
    {
        $this->medicineStock();
        $this->insertRabiesStock(302);
        $medicine = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $vaccine = $this->postJson('/api/admin/pets/101/vaccinations', [
            'inventory_item_id' => 302, 'inventory_quantity' => 1, 'clinic_appointment_id' => 201,
            'dose_amount' => 0.5, 'dose_unit' => 'mL',
        ])->assertCreated()->json('vaccination.id');
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->patchJson("/api/admin/pets/101/vaccinations/{$medicine}", ['notes' => 'Updated during consultation'])->assertOk();
        $this->postJson('/api/admin/clinic-cases/201/finish')->assertOk()->assertJsonPath('case.status', 'for_payment');
        foreach ([$medicine, $vaccine] as $id) {
            $this->assertNotNull(DB::table('vaccination_records')->where('id', $id)->value('published_at'));
            $this->assertDatabaseHas('clinic_charges', ['vaccination_record_id' => $id]);
        }
        $this->assertDatabaseCount('clinic_charges', 2);
        $this->assertSame(2, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        // Re-running the commit boundary cannot deduct stock or create charges twice.
        DB::transaction(fn () => app(\App\Services\ClinicTreatmentFinalizer::class)
            ->finalizeVisit(\App\Models\ClinicAppointment::whereKey(201)->lockForUpdate()->first(), auth()->user()));
        $this->assertDatabaseCount('clinic_charges', 2);
        $this->assertSame(2, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->deleteJson("/api/admin/pets/101/vaccinations/{$medicine}")->assertConflict();
    }

    public function test_visit_finalization_rolls_back_all_treatments_if_combined_stock_is_insufficient(): void
    {
        $this->medicineStock();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated();
        }
        $this->postJson('/api/admin/clinic-cases/201/finish')->assertUnprocessable();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 201, 'status' => 'in_consultation']);
        $this->assertSame(0, DB::table('vaccination_records')->whereNotNull('published_at')->count());
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertStockOnHand(301, '2');
    }

    public function test_current_visit_removal_is_scoped_and_has_no_billing_or_inventory_side_effects(): void
    {
        $this->medicineStock();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', $this->medicineTreatment())->assertCreated()->json('vaccination.id');
        $this->deleteJson("/api/admin/pets/999/vaccinations/{$id}")->assertNotFound();
        $this->deleteJson("/api/admin/pets/101/vaccinations/{$id}")->assertOk();
        $this->assertDatabaseMissing('vaccination_records', ['id' => $id]);
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
        $this->assertStockOnHand(301, '2');
    }

    public function test_incomplete_treatments_block_the_visit_boundary_and_external_entries_commit_without_stock(): void
    {
        $this->medicineStock();
        $id = $this->postJson('/api/admin/pets/101/vaccinations', [
            'clinic_appointment_id' => 201, 'treatment_type' => 'medicine',
        ])->assertCreated()->json('vaccination.id');
        $this->postJson('/api/admin/clinic-cases/201/finish')->assertUnprocessable()->assertJsonValidationErrors('treatments.'.$id.'.treatment');
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", [
            'vaccine_name' => 'External treatment', 'administered_date' => '2020-01-01',
        ])->assertOk();
        $this->postJson('/api/admin/clinic-cases/201/finish')->assertOk();
        $this->assertNotNull(DB::table('vaccination_records')->where('id', $id)->value('published_at'));
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertSame(0, DB::table('inventory_transactions')->where('type', 'stock_out')->count());
    }

    private function authenticateAs(string $role, int $userId): void
    {
        $this->insertUser($userId, ucfirst($role), 'User', $role, $role === 'staff' ? 'veterinarian' : null);

        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'role' => $role,
            'staff_type' => $role === 'staff' ? 'clinic' : null,
            'staff_subrole' => $role === 'staff' ? 'veterinarian' : null,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }

    private function insertUser(
        int $userId,
        string $firstName,
        string $lastName,
        string $role,
        ?string $staffSubrole = null,
        bool $isActive = true,
    ): void {
        DB::table('users')->updateOrInsert(
            ['user_id' => $userId],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'staff_type' => $staffSubrole ? 'clinic' : null,
                'staff_subrole' => $staffSubrole,
                'is_active' => $isActive,
            ],
        );
    }

    private function insertPet(int $petId, string $name, string $species): void
    {
        DB::table('pets')->insert([
            'pet_id' => $petId,
            'pet_name' => $name,
            'species' => $species,
        ]);
    }

    public function test_dose_formats_precision_catalog_and_other_units_are_validated_for_both_types(): void
    {
        $this->authenticateAs('admin', 1);
        $this->insertPet(101, 'Mochi', 'cat');
        foreach (['vaccine', 'medicine'] as $type) {
            foreach (['0', '-1', '1e2', 'Infinity', 'NaN', '0x10', '1.0001', '100000'] as $amount) {
                $this->postJson('/api/admin/pets/101/vaccinations', [
                    'treatment_type' => $type, 'dose_amount' => $amount, 'dose_unit' => 'mL',
                ])->assertUnprocessable()->assertJsonValidationErrors('dose_amount');
            }
            foreach (['capsule', 'drop', 'puff', 'dose'] as $unit) {
                $this->postJson('/api/admin/pets/101/vaccinations', [
                    'treatment_type' => $type, 'dose_amount' => '0.5', 'dose_unit' => $unit,
                ])->assertUnprocessable()->assertJsonValidationErrors('dose_amount');
            }
            $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => $type, 'dose_amount' => '0.5', 'dose_unit' => 'tablet',
            ])->assertCreated()->assertJsonPath('vaccination.dose_unit', 'tablet');
            $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => $type, 'dose_amount' => '2', 'dose_unit' => 'other',
            ])->assertUnprocessable()->assertJsonValidationErrors('dose_unit_other');
            $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => $type, 'dose_amount' => '2', 'dose_unit' => 'other', 'dose_unit_other' => 'OTHER',
            ])->assertUnprocessable()->assertJsonValidationErrors('dose_unit_other');
            $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => $type, 'dose_amount' => '2', 'dose_unit' => 'other', 'dose_unit_other' => 'measured scoop',
            ])->assertCreated()->assertJsonPath('vaccination.dose_unit', 'measured scoop');
            $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => $type, 'dose_amount' => '2', 'dose_unit' => 'unknown',
            ])->assertUnprocessable()->assertJsonValidationErrors('dose_unit');
            $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => $type, 'dose_amount' => '0.5', 'dose_unit' => 'other', 'dose_unit_other' => 'CAPSULE',
            ])->assertUnprocessable()->assertJsonValidationErrors('dose_amount');
            $this->postJson('/api/admin/pets/101/vaccinations', [
                'treatment_type' => $type, 'dose_amount' => '2', 'dose_unit' => 'other', 'dose_unit_other' => str_repeat('x', 31),
            ])->assertUnprocessable()->assertJsonValidationErrors('dose_unit_other');
        }
    }

    public function test_legacy_dose_units_are_editable_without_reinterpretation_and_new_units_are_canonical(): void
    {
        $this->authenticateAs('admin', 1);
        $this->insertPet(101, 'Mochi', 'cat');
        $id = $this->postJson('/api/admin/pets/101/vaccinations', ['dose_amount' => '0.125', 'dose_unit' => 'tablet'])
            ->assertCreated()->json('vaccination.id');
        foreach (['ml', 'legacy scoop', 'other', 'IU'] as $legacy) {
            DB::table('vaccination_records')->where('id', $id)->update(['dose_unit' => $legacy]);
            $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['notes' => 'Review historical record', 'dose_unit' => $legacy])
                ->assertOk()->assertJsonPath('vaccination.dose_unit', $legacy);
        }
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_unit' => 'mL'])
            ->assertOk()->assertJsonPath('vaccination.dose_unit', 'mL');
        $this->patchJson("/api/admin/pets/101/vaccinations/{$id}", ['dose_unit' => 'other', 'dose_unit_other' => 'ML'])
            ->assertOk()->assertJsonPath('vaccination.dose_unit', 'mL');
    }

    private function insertAppointment(int $appointmentId, int $petId, string $status = 'in_consultation'): void
    {
        DB::table('clinic_appointments')->insert([
            'id' => $appointmentId,
            'pet_id' => $petId,
            'appointment_reference' => "CL-{$appointmentId}",
            'status' => $status,
            'queue_number' => 3,
        ]);
    }

    private function insertInventoryItem(
        int $itemId,
        string $name,
        string $category,
        float $quantity,
        array $overrides = [],
    ): void {
        DB::table('inventory_items')->insert([
            'item_id' => $itemId,
            'item_name' => $name,
            'category' => $category,
            'quantity_on_hand' => $quantity,
            'selling_price' => 250,
            ...$overrides,
        ]);
    }

    private function insertStockIn(
        int $itemId,
        float $quantity,
        string $batchNumber,
        string $expiryDate,
        string $receivedAt,
    ): void {
        DB::table('inventory_transactions')->insert([
            'item_id' => $itemId,
            'type' => 'stock_in',
            'quantity' => $quantity,
            'reason' => 'purchase',
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'created_at' => $receivedAt,
        ]);
    }

    // 6 vials: an expired batch, a later-expiring batch received first, and the FEFO batch.
    private function insertRabiesStock(int $itemId): void
    {
        $this->insertInventoryItem($itemId, 'Rabies Vaccine', 'vaccine', 6, ['unit_cost' => 150]);
        $this->insertStockIn($itemId, 1, 'LOT-OLD', '2026-07-01', '2026-06-01 08:00:00');
        $this->insertStockIn($itemId, 3, 'LOT-LATE', '2027-06-01', '2026-07-01 08:00:00');
        $this->insertStockIn($itemId, 2, 'LOT-EARLY', '2026-12-01', '2026-07-10 08:00:00');
    }

    private function insertFinalizableDraft(?int $itemId, ?int $appointmentId): int
    {
        return $this->insertVaccination(101, 'Rabies Vaccine', '2026-07-23', [
            'inventory_item_id' => $itemId,
            'inventory_quantity' => 1,
            'clinic_appointment_id' => $appointmentId,
            'dose_amount' => 1,
            'dose_unit' => 'mL',
        ]);
    }

    private function validDraft(int $itemId): array
    {
        return [
            'inventory_item_id' => $itemId,
            'inventory_quantity' => 1,
            'dose_amount' => 1,
            'dose_unit' => 'mL',
        ];
    }

    private function assertStockOnHand(int $itemId, string $expected): void
    {
        $this->assertSame($expected, (string) DB::table('inventory_items')
            ->where('item_id', $itemId)
            ->value('quantity_on_hand'));
    }

    private function insertVaccination(
        int $petId,
        string $vaccineName,
        string $administeredDate,
        array $overrides = [],
    ): int {
        return DB::table('vaccination_records')->insertGetId([
            'pet_id' => $petId,
            'vaccine_name' => $vaccineName,
            'administered_date' => $administeredDate,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }
}
