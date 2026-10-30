<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\InventoryItem;
use App\Models\Pet;
use App\Models\User;
use App\Models\VaccinationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VaccinationRecordFoundationTest extends TestCase
{
    private $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('staff');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->json('customer_locked_fields')->nullable();
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('pet_name');
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('appointment_reference');
            $table->string('status')->default('completed');
            $table->date('appointment_date');
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->autoIncrement();
            $table->string('item_name');
            $table->string('category');
            $table->string('dose_unit')->nullable();
        });

        $this->migration = require base_path(
            'database/migrations/2026_07_23_000004_create_vaccination_records_table.php',
        );
        $this->migration->up();
        (require database_path('migrations/2026_10_12_000001_extend_vaccination_records_for_treatments.php'))->up();
        (require database_path('migrations/2026_10_12_000004_separate_treatment_clinical_details_from_inventory.php'))->up();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('vaccination_records')) {
            $this->migration->down();
        }

        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_migration_creates_the_approved_columns_and_indexes_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertTrue(Schema::hasColumns('vaccination_records', [
            'id',
            'pet_id',
            'clinic_appointment_id',
            'inventory_item_id',
            'vaccine_name',
            'product_name',
            'manufacturer',
            'batch_number',
            'administered_date',
            'next_due_date',
            'product_expiry_date',
            'dose_amount',
            'dose_unit',
            'route',
            'administration_site',
            'recorded_by_user_id',
            'notes',
            'published_at',
            'published_by_user_id',
            'voided_at',
            'voided_by_user_id',
            'void_reason',
            'created_at',
            'updated_at',
        ]));

        $indexNames = collect(Schema::getIndexes('vaccination_records'))
            ->pluck('name');

        foreach ([
            'vaccination_pet_date_idx',
            'vaccination_client_visibility_idx',
            'vaccination_next_due_idx',
            'vaccination_duplicate_lookup_idx',
            'vaccination_appointment_idx',
            'vaccination_inventory_item_idx',
            'vaccination_recorded_by_idx',
            'vaccination_published_by_idx',
            'vaccination_voided_by_idx',
        ] as $indexName) {
            $this->assertTrue($indexNames->contains($indexName), "Missing index {$indexName}.");
        }
    }

    public function test_model_exposes_all_approved_relationships_and_historical_snapshots(): void
    {
        $this->insertUser(10, 'Administering', 'Staff');
        $this->insertUser(11, 'Recording', 'Staff');
        $this->insertUser(12, 'Publishing', 'Admin');
        $this->insertUser(13, 'Voiding', 'Admin');
        $this->insertPet(101);
        $this->insertAppointment(201, 101);
        $this->insertInventoryItem(301);

        $record = VaccinationRecord::create([
            'pet_id' => 101,
            'clinic_appointment_id' => 201,
            'inventory_item_id' => 301,
            'vaccine_name' => 'Rabies',
            'product_name' => 'Historical Product Name',
            'manufacturer' => 'Historical Manufacturer',
            'batch_number' => 'LOT-2026-001',
            'administered_date' => '2026-07-23',
            'next_due_date' => '2027-07-23',
            'product_expiry_date' => '2027-12-31',
            'dose_amount' => 1,
            'dose_unit' => 'mL',
            'route' => 'subcutaneous',
            'administration_site' => 'Right shoulder',
            'recorded_by_user_id' => 11,
            'published_at' => '2026-07-23 10:00:00',
            'published_by_user_id' => 12,
            'voided_at' => '2026-07-23 11:00:00',
            'voided_by_user_id' => 13,
            'void_reason' => 'Duplicate historical entry.',
        ]);

        $this->assertSame(101, $record->pet->pet_id);
        $this->assertSame(201, $record->clinicAppointment->id);
        $this->assertSame(301, $record->inventoryItem->item_id);
        $this->assertSame(11, $record->recordedBy->user_id);
        $this->assertSame(12, $record->publishedBy->user_id);
        $this->assertSame(13, $record->voidedBy->user_id);

        $this->assertTrue(Pet::findOrFail(101)->vaccinationRecords->contains($record));
        $this->assertTrue(ClinicAppointment::findOrFail(201)->vaccinationRecords->contains($record));
        $this->assertTrue(InventoryItem::findOrFail(301)->vaccinationRecords->contains($record));
        $this->assertTrue(User::findOrFail(11)->vaccinationsRecorded->contains($record));
        $this->assertTrue(User::findOrFail(12)->vaccinationsPublished->contains($record));
        $this->assertTrue(User::findOrFail(13)->vaccinationsVoided->contains($record));

        $this->assertSame('1.000', $record->dose_amount);
        $this->assertSame('2026-07-23', $record->administered_date->toDateString());
        $this->assertSame('Historical Product Name', $record->product_name);
        $this->assertSame('Historical Manufacturer', $record->manufacturer);
        $this->assertSame('LOT-2026-001', $record->batch_number);
    }

    public function test_foreign_keys_protect_pet_history_and_null_unavailable_links(): void
    {
        $this->insertUser(10, 'Historical', 'Staff');
        $this->insertPet(101);
        $this->insertAppointment(201, 101);
        $this->insertInventoryItem(301);

        $record = VaccinationRecord::create([
            'pet_id' => 101,
            'clinic_appointment_id' => 201,
            'inventory_item_id' => 301,
            'vaccine_name' => 'Rabies',
            'administered_date' => '2026-07-23',
            'recorded_by_user_id' => 10,
            'published_at' => '2026-07-23 10:00:00',
            'published_by_user_id' => 10,
            'voided_at' => '2026-07-23 11:00:00',
            'voided_by_user_id' => 10,
            'void_reason' => 'Entered twice.',
        ]);

        try {
            DB::table('pets')->where('pet_id', 101)->delete();
            $this->fail('Deleting a pet with vaccination history should be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('pets', ['pet_id' => 101]);
            $this->assertDatabaseHas('vaccination_records', ['id' => $record->id, 'pet_id' => 101]);
        }

        DB::table('clinic_appointments')->where('id', 201)->delete();
        DB::table('inventory_items')->where('item_id', 301)->delete();
        DB::table('users')->where('user_id', 10)->delete();

        $record->refresh();

        $this->assertNull($record->clinic_appointment_id);
        $this->assertNull($record->inventory_item_id);
        $this->assertNull($record->recorded_by_user_id);
        $this->assertNull($record->published_by_user_id);
        $this->assertNull($record->voided_by_user_id);
        $this->assertSame('Rabies', $record->vaccine_name);
        $this->assertSame('Entered twice.', $record->void_reason);
    }

    public function test_visibility_publication_requirements_and_due_status_are_reusable(): void
    {
        $this->insertPet(101);

        VaccinationRecord::create([
            'pet_id' => 101,
            'vaccine_name' => 'Published Vaccine',
            'administered_date' => '2026-07-23',
            'published_at' => '2026-07-23 09:00:00',
        ]);
        VaccinationRecord::create([
            'pet_id' => 101,
            'vaccine_name' => 'Voided Vaccine',
            'administered_date' => '2026-07-23',
            'published_at' => '2026-07-23 09:00:00',
            'voided_at' => '2026-07-23 10:00:00',
        ]);
        VaccinationRecord::create([
            'pet_id' => 101,
            'vaccine_name' => 'Draft Vaccine',
            'administered_date' => '2026-07-23',
        ]);

        $this->assertSame(
            ['Published Vaccine'],
            VaccinationRecord::visibleToClients()->pluck('vaccine_name')->all(),
        );
        $this->assertTrue(VaccinationRecord::firstWhere('vaccine_name', 'Published Vaccine')->hasPublishingRequirements());
        $this->assertTrue(VaccinationRecord::firstWhere('vaccine_name', 'Draft Vaccine')->hasPublishingRequirements());
        $this->assertTrue((new VaccinationRecord([
            'vaccine_name' => 'Provider ID Vaccine',
            'administered_date' => '2026-07-23',
            ]))->hasPublishingRequirements());
        $this->assertFalse((new VaccinationRecord([
            'vaccine_name' => '',
            'administered_date' => '2026-07-23',
            ]))->hasPublishingRequirements());
        $this->assertFalse((new VaccinationRecord([
            'vaccine_name' => 'Missing Date Vaccine',
            ]))->hasPublishingRequirements());

        $asOf = CarbonImmutable::parse('2026-07-23');
        $this->assertSame(VaccinationRecord::STATUS_UNKNOWN, $this->recordWithDueDate(null)->dueStatus($asOf));
        $this->assertSame(VaccinationRecord::STATUS_OVERDUE, $this->recordWithDueDate('2026-07-22')->dueStatus($asOf));
        $this->assertSame(VaccinationRecord::STATUS_DUE_SOON, $this->recordWithDueDate('2026-07-23')->dueStatus($asOf));
        $this->assertSame(VaccinationRecord::STATUS_DUE_SOON, $this->recordWithDueDate('2026-08-22')->dueStatus($asOf));
        $this->assertSame(VaccinationRecord::STATUS_CURRENT, $this->recordWithDueDate('2026-08-23')->dueStatus($asOf));
        $this->assertSame(30, VaccinationRecord::DUE_SOON_DAYS);
    }

    public function test_valid_booster_doses_are_not_blocked_by_a_unique_constraint(): void
    {
        $this->insertPet(101);

        $dose = [
            'pet_id' => 101,
            'vaccine_name' => 'Combination Vaccine',
            'batch_number' => 'BATCH-001',
            'administered_date' => '2026-07-23',
        ];

        VaccinationRecord::create($dose);
        VaccinationRecord::create($dose);

        $this->assertSame(2, VaccinationRecord::count());
    }

    public function test_required_fields_and_pet_foreign_key_are_enforced_while_drafts_remain_possible(): void
    {
        $this->insertPet(101);

        $validDraft = [
            'pet_id' => 101,
            'vaccine_name' => 'Rabies',
            'administered_date' => '2026-07-23',
        ];

        $draftId = DB::table('vaccination_records')->insertGetId($validDraft);

        $this->assertDatabaseHas('vaccination_records', [
            'id' => $draftId,
            'pet_id' => 101,
            'published_at' => null,
            'published_by_user_id' => null,
            'voided_at' => null,
        ]);

        foreach (['pet_id'] as $requiredField) {
            $invalidRecord = $validDraft;
            unset($invalidRecord[$requiredField]);
            $this->assertInsertFails($invalidRecord);
        }

        $incompleteId = DB::table('vaccination_records')->insertGetId(['pet_id' => 101]);
        $this->assertFalse(VaccinationRecord::findOrFail($incompleteId)->hasPublishingRequirements());

        $invalidPet = $validDraft;
        $invalidPet['pet_id'] = 999;
        $this->assertInsertFails($invalidPet);
    }

    private function insertUser(int $userId, string $firstName, string $lastName): void
    {
        DB::table('users')->insert([
            'user_id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => 'staff',
        ]);
    }

    private function insertPet(int $petId): void
    {
        DB::table('pets')->insert([
            'pet_id' => $petId,
            'pet_name' => "Pet {$petId}",
        ]);
    }

    private function insertAppointment(int $appointmentId, int $petId): void
    {
        DB::table('clinic_appointments')->insert([
            'id' => $appointmentId,
            'pet_id' => $petId,
            'appointment_reference' => "CL-{$appointmentId}",
            'status' => 'completed',
            'appointment_date' => '2026-07-23',
        ]);
    }

    private function insertInventoryItem(int $itemId): void
    {
        DB::table('inventory_items')->insert([
            'item_id' => $itemId,
            'item_name' => "Vaccine Item {$itemId}",
            'category' => 'vaccine',
        ]);
    }

    private function recordWithDueDate(?string $nextDueDate): VaccinationRecord
    {
        return new VaccinationRecord([
            'vaccine_name' => 'Status Test Vaccine',
            'administered_date' => '2026-07-23',
            'next_due_date' => $nextDueDate,
        ]);
    }

    private function assertInsertFails(array $record): void
    {
        try {
            DB::table('vaccination_records')->insert($record);
            $this->fail('The invalid vaccination record should have been rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
