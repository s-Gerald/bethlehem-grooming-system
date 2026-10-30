<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPetVaccinationsTest extends TestCase
{
    private $vaccinationMigration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->json('customer_locked_fields')->nullable();
            $table->increments('pet_id');
            $table->unsignedInteger('user_id');
            $table->string('pet_name');
            $table->string('species')->nullable();
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('appointment_reference');
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->autoIncrement();
            $table->string('item_name');
            $table->string('category');
            $table->string('dose_unit')->nullable();
            $table->decimal('quantity_on_hand', 10, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('supplier_name')->nullable();
        });

        $this->vaccinationMigration = require base_path(
            'database/migrations/2026_07_23_000004_create_vaccination_records_table.php',
        );
        $this->vaccinationMigration->up();
        (require database_path('migrations/2026_10_12_000001_extend_vaccination_records_for_treatments.php'))->up();
        (require database_path('migrations/2026_10_12_000004_separate_treatment_clinical_details_from_inventory.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (Schema::hasTable('vaccination_records')) {
            $this->vaccinationMigration->down();
        }

        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_vaccination_history_requires_authentication(): void
    {
        $this->getJson('/api/pets/101/vaccinations')->assertUnauthorized();
    }

    public function test_owner_can_retrieve_their_pet_while_foreign_and_missing_pets_are_indistinguishable(): void
    {
        $this->authenticateCustomer(10);
        $this->insertPet(101, 10, 'Mochi');
        $this->insertPet(202, 20, 'Not My Pet');
        $this->insertVaccination(101, 'Owned Published Vaccine', '2026-07-20');
        $this->insertVaccination(202, 'Foreign Published Vaccine', '2026-07-21');

        $this->getJson('/api/pets/101/vaccinations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'vaccinations')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'Owned Published Vaccine');

        $foreign = $this->getJson('/api/pets/202/vaccinations')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);

        $missing = $this->getJson('/api/pets/999/vaccinations')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Pet not found.',
            ]);

        $this->assertSame($foreign->getContent(), $missing->getContent());
        $this->assertStringNotContainsString('Foreign Published Vaccine', $foreign->getContent());
    }

    public function test_records_are_pet_isolated_sorted_and_limited_to_published_non_voided_history(): void
    {
        $this->authenticateCustomer(10);
        $this->insertPet(101, 10, 'Mochi');
        $this->insertPet(102, 10, 'Bruno');
        $this->insertPet(202, 20, 'Other Owner Pet');

        $this->insertVaccination(101, 'Mochi Older', '2026-06-10');
        $this->insertVaccination(101, 'Mochi Newer', '2026-07-20');
        $this->insertVaccination(101, 'Mochi Draft', '2026-07-22', [
            'published_at' => null,
        ]);
        $this->insertVaccination(101, 'Mochi Voided', '2026-07-21', [
            'voided_at' => '2026-07-22 09:00:00',
            'void_reason' => 'Entered in error.',
        ]);
        $this->insertVaccination(102, 'Sibling Pet Vaccine', '2026-07-23');
        $this->insertVaccination(202, 'Other Customer Vaccine', '2026-07-23');

        $response = $this->getJson('/api/pets/101/vaccinations')
            ->assertOk()
            ->assertJsonCount(2, 'vaccinations')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'Mochi Newer')
            ->assertJsonPath('vaccinations.1.vaccine_name', 'Mochi Older');

        foreach ([
            'Mochi Draft',
            'Mochi Voided',
            'Sibling Pet Vaccine',
            'Other Customer Vaccine',
        ] as $hiddenRecord) {
            $response->assertJsonMissing(['vaccine_name' => $hiddenRecord]);
        }
    }

    public function test_response_uses_an_exact_customer_safe_whitelist(): void
    {
        $this->authenticateCustomer(10);
        $this->insertUser(20, 'Linked', 'Provider', 'admin', [
            'email' => 'provider-private@example.test',
            'phone' => '09171234567',
        ]);
        $this->insertUser(21, 'Internal', 'Recorder', 'staff');
        $this->insertPet(101, 10, 'Mochi');

        DB::table('clinic_appointments')->insert([
            'id' => 501,
            'pet_id' => 101,
            'appointment_reference' => 'CL-SAFE-501',
        ]);
        DB::table('inventory_items')->insert([
            'item_id' => 601,
            'item_name' => 'PRIVATE INVENTORY PRODUCT',
            'category' => 'vaccine',
            'quantity_on_hand' => 999,
            'unit_cost' => 321.50,
            'supplier_name' => 'PRIVATE SUPPLIER',
        ]);

        $this->insertVaccination(101, 'Rabies', '2026-07-20', [
            'clinic_appointment_id' => 501,
            'inventory_item_id' => 601,
            'product_name' => 'Rabies Shield',
            'manufacturer' => 'Example Laboratories',
            'batch_number' => 'LOT-2026-01',
            'next_due_date' => '2027-07-20',
            'product_expiry_date' => '2027-12-31',
            'dose_amount' => 1,
            'dose_unit' => 'mL',
            'route' => 'subcutaneous',
            'administration_site' => 'Right shoulder',
            'recorded_by_user_id' => 21,
            'notes' => 'PRIVATE INTERNAL VACCINATION NOTES',
            'published_by_user_id' => 21,
            'voided_by_user_id' => 21,
            'void_reason' => 'PRIVATE VOID REASON',
        ]);

        $response = $this->getJson('/api/pets/101/vaccinations')->assertOk();
        $record = $response->json('vaccinations.0');

        $this->assertSame([
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
            'appointment_reference',
            'due_status',
        ], array_keys($record));
        $this->assertSame('CL-SAFE-501', $record['appointment_reference']);

        $encoded = json_encode($response->json());
        foreach ([
            'pet_id',
            'clinic_appointment_id',
            'inventory_item_id',
            'administered_by_user_id',
            'recorded_by_user_id',
            'published_at',
            'published_by_user_id',
            'voided_at',
            'voided_by_user_id',
            'void_reason',
            'notes',
            'inventory_item',
            'clinic_appointment',
            'administered_by',
            'PRIVATE INTERNAL VACCINATION NOTES',
            'PRIVATE VOID REASON',
            'PRIVATE INVENTORY PRODUCT',
            'PRIVATE SUPPLIER',
            'provider-private@example.test',
            '09171234567',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $encoded);
        }
        $this->assertArrayNotHasKey('id', $record);
        $this->assertSame(
            999.0,
            (float) DB::table('inventory_items')->where('item_id', 601)->value('quantity_on_hand'),
        );
    }

    public function test_response_no_longer_exposes_treatment_provider_details(): void
    {
        $this->authenticateCustomer(10);
        $this->insertUser(20, 'Alex', 'Reyes', 'staff', [
            'email' => 'alex-private@example.test',
            'phone' => '09991234567',
        ]);
        $this->insertPet(101, 10, 'Mochi');

        $this->insertVaccination(101, 'Snapshot Provider', '2026-07-23', [
            ]);
        $this->insertVaccination(101, 'Linked Provider', '2026-07-22', [
            ]);
        $this->insertVaccination(101, 'No Provider', '2026-07-21');

        $records = collect(
            $this->getJson('/api/pets/101/vaccinations')
                ->assertOk()
                ->json('vaccinations'),
        )->keyBy('vaccine_name');


        foreach ($records as $record) $this->assertArrayNotHasKey('administering_provider', $record);
        $encoded = json_encode($records);
        $this->assertStringNotContainsString('alex-private@example.test', $encoded);
        $this->assertStringNotContainsString('09991234567', $encoded);
        $this->assertStringNotContainsString('"role"', $encoded);
        $this->assertStringNotContainsString('"user_id"', $encoded);
    }

    public function test_due_status_reuses_the_model_threshold_and_ignores_product_expiration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00'));
        $this->authenticateCustomer(10);
        $this->insertPet(101, 10, 'Mochi');

        $this->insertVaccination(101, 'Current Vaccine', '2026-07-20', [
            'next_due_date' => '2026-08-23',
            'product_expiry_date' => '2026-07-01',
        ]);
        $this->insertVaccination(101, 'Due Soon Vaccine', '2026-07-19', [
            'next_due_date' => '2026-08-22',
            'product_expiry_date' => '2027-12-31',
        ]);
        $this->insertVaccination(101, 'Overdue Vaccine', '2026-07-18', [
            'next_due_date' => '2026-07-22',
            'product_expiry_date' => '2027-12-31',
        ]);
        $this->insertVaccination(101, 'Unknown Vaccine', '2026-07-17', [
            'next_due_date' => null,
            'product_expiry_date' => '2026-07-01',
        ]);

        $records = collect(
            $this->getJson('/api/pets/101/vaccinations')
                ->assertOk()
                ->json('vaccinations'),
        )->keyBy('vaccine_name');

        $this->assertSame('current', $records['Current Vaccine']['due_status']);
        $this->assertSame('due_soon', $records['Due Soon Vaccine']['due_status']);
        $this->assertSame('overdue', $records['Overdue Vaccine']['due_status']);
        $this->assertSame('unknown', $records['Unknown Vaccine']['due_status']);
    }

    private function authenticateCustomer(int $userId): void
    {
        $this->insertUser($userId, 'Jamie', 'Santos', 'customer');

        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'role' => 'customer',
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }

    private function insertUser(
        int $userId,
        string $firstName,
        string $lastName,
        string $role,
        array $overrides = [],
    ): void {
        DB::table('users')->updateOrInsert(
            ['user_id' => $userId],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => null,
                'phone' => null,
                'role' => $role,
                ...$overrides,
            ],
        );
    }

    private function insertPet(int $petId, int $userId, string $name): void
    {
        DB::table('pets')->insert([
            'pet_id' => $petId,
            'user_id' => $userId,
            'pet_name' => $name,
            'species' => 'cat',
        ]);
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
            'published_at' => '2026-07-23 10:00:00',
            'created_at' => '2026-07-23 10:00:00',
            'updated_at' => '2026-07-23 10:00:00',
            ...$overrides,
        ]);
    }
}
