<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPetProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
        });
        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->text('cancellation_reason')->nullable();
        });
        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->string('grooming_state')->nullable();
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
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->date('appointment_date');
            $table->string('status');
            $table->unsignedInteger('pet_id');
            $table->text('chief_complaint')->nullable();
        });
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->text('chief_complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('findings')->nullable();
            $table->text('treatment_given')->nullable();
            $table->text('vet_notes')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_notes')->nullable();
        });
        Schema::create('clinic_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedInteger('heart_rate_bpm')->nullable();
            $table->unsignedInteger('respiratory_rate_bpm')->nullable();
            $table->unsignedTinyInteger('body_condition_score')->nullable();
        });
        Schema::create('clinic_medications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('drug_name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->text('instructions')->nullable();
        });
        Schema::create('vaccination_records', function (Blueprint $table) {
            $table->string('treatment_type')->default('vaccine');
            $table->id();
            $table->unsignedInteger('pet_id');
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->string('vaccine_name');
            $table->string('product_name')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('administered_date');
            $table->date('next_due_date')->nullable();
            $table->date('product_expiry_date')->nullable();
            $table->decimal('dose_amount', 8, 2)->nullable();
            $table->string('dose_unit')->nullable();
            $table->string('route')->nullable();
            $table->string('administration_site')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'clinic_confinements', 'clinic_case_services', 'clinic_charges', 'clinic_services',
            'vaccination_records',
            'clinic_attachments',
            'clinic_medications',
            'clinic_vitals',
            'clinic_records',
            'clinic_appointments',
            'booking_services',
            'services',
            'booking_pets',
            'bookings',
            'time_windows',
            'pets',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_admin_pet_profile_requires_staff_or_admin_role(): void
    {
        $this->insertPet(101, 'Mochi');
        $this->authenticate(10, 'customer');

        $this->getJson('/api/admin/pets/101/profile')
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized. Admin or staff access required.');
    }

    public function test_staff_can_retrieve_pet_scoped_grooming_medical_and_vaccination_records(): void
    {
        $this->insertPet(101, 'Mochi');
        $this->insertPet(202, 'Other Pet');
        $this->authenticate(20, 'staff');

        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '8:00 AM - 10:00 AM']);
        DB::table('bookings')->insert([
            'booking_id' => 301,
            'booking_reference' => 'BAC-301',
            'user_id' => 10,
            'window_id' => 1,
            'booking_date' => '2026-08-01',
            'status' => 'archived',
            'paid' => true,
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 401,
            'booking_id' => 301,
            'pet_id' => 101,
            'grooming_state' => 'finished',
            'grooming_start_time' => '2026-08-01 08:30:00',
            'grooming_end_time' => '2026-08-01 09:30:00',
        ]);
        DB::table('services')->insert(['service_id' => 501, 'service_name' => 'Full Groom']);
        DB::table('booking_services')->insert([
            'booking_service_id' => 601,
            'booking_id' => 301,
            'booking_pet_id' => 401,
            'service_id' => 501,
        ]);

        DB::table('clinic_appointments')->insert([
            'id' => 701,
            'appointment_reference' => 'CL-701',
            'appointment_date' => '2026-08-02',
            'status' => 'completed',
            'pet_id' => 101,
            'chief_complaint' => 'Itchy skin',
        ]);
        $clinicRecordId = DB::table('clinic_records')->insertGetId([
            'clinic_appointment_id' => 701,
            'chief_complaint' => 'Itchy skin',
            'diagnosis' => 'Dermatitis',
            'findings' => 'Erythema on flanks',
            'treatment_given' => 'Medicated bath',
            'vet_notes' => 'Monitor for recurrence',
        ]);
        DB::table('clinic_attachments')->insert([
            'clinic_record_id' => $clinicRecordId,
            'file_name' => 'skin-scrape.pdf',
            'file_path' => 'clinic/attachments/701/skin-scrape.pdf',
            'file_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'label' => 'Skin scrape result',
            'created_at' => '2026-08-02 12:00:00',
            'updated_at' => '2026-08-02 12:00:00',
        ]);
        DB::table('clinic_medications')->insert([
            'clinic_record_id' => $clinicRecordId,
            'drug_name' => 'Skin Care Medicine',
        ]);
        DB::table('vaccination_records')->insert([
            'pet_id' => 101,
            'clinic_appointment_id' => 701,
            'vaccine_name' => 'Rabies',
            'administered_date' => '2026-08-02',
            'published_at' => '2026-08-02 12:00:00',
            'created_at' => '2026-08-02 12:00:00',
            'updated_at' => '2026-08-02 12:00:00',
        ]);

        $this->getJson('/api/admin/pets/101/profile')
            ->assertOk()
            ->assertJsonPath('grooming_records.0.booking_reference', 'BAC-301')
            ->assertJsonPath('grooming_records.0.services.0', 'Full Groom')
            ->assertJsonPath('medical_records.0.appointment_reference', 'CL-701')
            ->assertJsonPath('medical_records.0.appointment_id', 701)
            ->assertJsonPath('medical_records.0.diagnosis', 'Dermatitis')
            ->assertJsonPath('medical_records.0.findings', 'Erythema on flanks')
            ->assertJsonPath('medical_records.0.vet_notes', 'Monitor for recurrence')
            ->assertJsonPath('medical_records.0.attachments.0.file_name', 'skin-scrape.pdf')
            ->assertJsonPath('medical_records.0.attachments.0.label', 'Skin scrape result')
            ->assertJsonMissingPath('medical_records.0.medications')
            ->assertJsonPath('vaccinations.0.vaccine_name', 'Rabies')->assertJsonMissingPath('vaccinations.0.administering_provider');

        $this->getJson('/api/admin/pets/999/profile')
            ->assertNotFound()
            ->assertJsonPath('message', 'Pet not found.');
    }

    public function test_finalized_medical_history_is_available_before_payment_and_excludes_unfinished_visits(): void
    {
        $this->insertPet(101, 'Mochi');
        $this->authenticate(20, 'staff');
        foreach (['in_consultation', 'for_payment', 'completed'] as $index => $status) {
            $id = 700 + $index;
            DB::table('clinic_appointments')->insert(['id' => $id, 'appointment_reference' => 'CL-'.$id,
                'appointment_date' => '2026-08-02', 'status' => $status, 'pet_id' => 101]);
            DB::table('clinic_records')->insert(['clinic_appointment_id' => $id, 'diagnosis' => $status]);
        }
        $this->getJson('/api/admin/pets/101/profile')->assertOk()->assertJsonCount(2, 'medical_records')
            ->assertJsonPath('medical_records.0.appointment_id', 702)
            ->assertJsonPath('medical_records.1.appointment_id', 701);
    }

    public function test_service_case_summaries_and_confinement_history_are_paginated_and_pet_scoped(): void
    {
        $this->insertPet(101, 'Mochi');
        $this->insertPet(202, 'Other Pet');
        $this->authenticate(20, 'staff');
        $this->createCaseHistoryTables();
        DB::table('clinic_services')->insert(['id' => 1, 'name' => 'Renamed catalogue service']);
        foreach (range(1, 23) as $id) {
            DB::table('clinic_appointments')->insert(['id' => $id, 'pet_id' => $id === 23 ? 202 : 101,
                'appointment_reference' => 'CL-'.$id, 'appointment_date' => '2026-10-01', 'status' => 'completed']);
            DB::table('clinic_case_services')->insert(['id' => $id, 'clinic_appointment_id' => $id, 'clinic_service_id' => 1,
                'status' => $id === 22 ? 'cancelled' : 'performed', 'service_snapshot' => json_encode(['name' => 'Saved service']),
                'notes' => 'Saved note', 'applied_price' => 500, 'performed_at' => '2026-10-01 10:00:00']);
            DB::table('clinic_charges')->insert(['clinic_case_service_id' => $id, 'amount' => 500]);
            DB::table('clinic_confinements')->insert(['id' => $id, 'clinic_appointment_id' => $id, 'clinic_case_service_id' => $id,
                'admitted_at' => '2026-10-01 10:00:00', 'discharged_at' => '2026-10-02 10:00:00', 'reason' => 'Observation', 'discharge_note' => 'Recovered']);
        }
        // More than one old page of services belongs to a single case, including repeated services.
        foreach (range(100, 124) as $id) {
            DB::table('clinic_case_services')->insert(['id' => $id, 'clinic_appointment_id' => 21, 'clinic_service_id' => 1,
                'status' => 'performed', 'service_snapshot' => json_encode(['name' => 'Saved service']),
                'quantity' => 2.5, 'applied_price' => 20, 'performed_at' => '2026-10-02 10:00:00']);
        }
        foreach (['ordered', 'cancelled'] as $status) {
            DB::table('clinic_case_services')->insert(['clinic_appointment_id' => 21, 'clinic_service_id' => 1,
                'status' => $status, 'service_snapshot' => '{}', 'applied_price' => 9999, 'performed_at' => '2026-10-03 10:00:00']);
        }
        $first = $this->getJson('/api/admin/pets/101/profile?history=services')->assertOk()->assertJsonPath('total', 21)
            ->assertJsonPath('last_page', 2)->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.appointment_id', 21)->assertJsonPath('data.0.appointment_reference', 'CL-21')
            ->assertJsonPath('data.0.service_count', 26)->assertJsonPath('data.0.service_total', '1750.00')
            ->assertJsonPath('data.1.service_count', 1)->assertJsonPath('data.1.service_total', '500.00');
        $second = $this->getJson('/api/admin/pets/101/profile?history=services&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.appointment_id', 1);
        $ids = [...array_column($first->json('data'), 'appointment_id'), ...array_column($second->json('data'), 'appointment_id')];
        $this->assertCount(21, array_unique($ids));
        $this->assertSame(range(21, 1), $ids);
        $this->authenticate(20, 'admin');
        $this->getJson('/api/admin/pets/101/cases/21/details')->assertOk()->assertJsonCount(26, 'services')
            ->assertJsonPath('services.0.service_snapshot.name', 'Saved service')
            ->assertJsonPath('services.1.service_snapshot.name', 'Saved service');
        $this->getJson('/api/admin/pets/101/profile?history=confinement')->assertOk()->assertJsonPath('total', 22)
            ->assertJsonCount(20, 'data')->assertJsonPath('data.0.id', 22)
            ->assertJsonPath('data.0.discharge_note', 'Recovered')
            ->assertJsonPath('data.0.case_service.service_snapshot.name', 'Saved service');
        $this->getJson('/api/admin/pets/101/profile?history=confinement&page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/pets/101/profile?history=unknown')->assertUnprocessable();
        $this->authenticate(10, 'customer');
        $this->getJson('/api/admin/pets/101/profile?history=services')->assertForbidden();
        $this->getJson('/api/admin/pets/101/profile?history=confinement')->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fractionalServiceTotals')]
    public function test_service_case_summary_uses_saved_line_totals_and_handles_empty_history(
        float $quantity, float $price, string $expectedTotal,
    ): void
    {
        $this->insertPet(101, 'Mochi'); $this->authenticate(20, 'admin'); $this->createCaseHistoryTables();
        $this->getJson('/api/admin/pets/101/profile?history=services')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        DB::table('clinic_appointments')->insert(['id' => 1, 'pet_id' => 101, 'appointment_reference' => 'CL-20261014-001',
            'appointment_date' => '2026-10-14', 'status' => 'completed']);
        DB::table('clinic_services')->insert(['id' => 1, 'name' => 'Edited catalogue name']);
        foreach ([1, 2] as $id) {
            DB::table('clinic_case_services')->insert(['id' => $id, 'clinic_appointment_id' => 1, 'clinic_service_id' => 1,
                'status' => 'performed', 'service_snapshot' => json_encode(['name' => 'Saved service']),
                'quantity' => $quantity, 'applied_price' => $price, 'performed_at' => '2026-10-14 10:00:00']);
        }
        $this->getJson('/api/admin/pets/101/profile?history=services')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.service_count', 2)->assertJsonPath('data.0.service_total', $expectedTotal);
    }

    public static function fractionalServiceTotals(): array
    {
        return [
            'original half cent per line' => [0.5, 0.03, '0.04'],
            'fifteen cents per line' => [0.5, 0.29, '0.30'],
            'twenty-nine cents per line' => [0.5, 0.57, '0.58'],
            'smallest half cent' => [0.5, 0.01, '0.02'],
            'below half cent' => [0.33, 0.01, '0.00'],
            'whole quantity' => [3, 0.07, '0.42'],
        ];
    }

    public function test_case_details_share_existing_formats_and_are_case_and_pet_scoped(): void
    {
        $this->insertPet(101, 'Mochi');
        $this->insertPet(202, 'Other Pet');
        $this->authenticate(20, 'admin');
        $this->createCaseHistoryTables();
        foreach ([701 => 101, 702 => 101, 703 => 202] as $id => $petId) {
            DB::table('clinic_appointments')->insert(['id' => $id, 'pet_id' => $petId,
                'appointment_reference' => 'CL-20261014-'.$id, 'appointment_date' => '2026-10-01', 'status' => 'completed']);
        }
        $recordId = DB::table('clinic_records')->insertGetId(['clinic_appointment_id' => 701, 'diagnosis' => 'Dermatitis', 'findings' => 'Redness']);
        DB::table('clinic_attachments')->insert(['clinic_record_id' => $recordId, 'file_name' => 'sample-result.pdf',
            'file_path' => 'sample-result.pdf', 'label' => 'Sample result']);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 701, 'weight_kg' => 12.5]);
        foreach ([701, 701, 702, null] as $index => $caseId) {
            DB::table('vaccination_records')->insert(['pet_id' => 101, 'clinic_appointment_id' => $caseId,
                'vaccine_name' => 'Treatment '.$index, 'administered_date' => '2026-10-01',
                'published_at' => $index === 0 ? '2026-10-01 12:00:00' : null]);
        }
        DB::table('clinic_services')->insert(['id' => 1, 'name' => 'Renamed service']);
        foreach (range(1, 24) as $id) {
            DB::table('clinic_case_services')->insert(['id' => $id, 'clinic_appointment_id' => $id === 24 ? 702 : 701,
                'clinic_service_id' => 1, 'status' => $id === 23 ? 'cancelled' : 'performed',
                'service_snapshot' => json_encode(['name' => 'Saved service '.$id]), 'notes' => 'Saved note',
                'applied_price' => 500, 'performed_at' => '2026-10-01 10:00:00']);
        }
        DB::table('clinic_confinements')->insert(['id' => 1, 'clinic_appointment_id' => 701, 'clinic_case_service_id' => 1,
            'admitted_at' => '2026-10-01 10:00:00', 'discharged_at' => '2026-10-02 10:00:00', 'reason' => 'Observation', 'discharge_note' => 'Recovered']);
        $this->getJson('/api/admin/pets/101/cases/701/details')->assertOk()
            ->assertJsonPath('case.appointment_reference', 'CL-20261014-701')
            ->assertJsonPath('case.appointment_date', '2026-10-01')
            ->assertJsonPath('medical_record.appointment_id', 701)->assertJsonPath('medical_record.diagnosis', 'Dermatitis')
            ->assertJsonPath('medical_record.attachments.0.file_name', 'sample-result.pdf')
            ->assertJsonPath('medical_record.vitals.weight_kg', '12.50')->assertJsonCount(2, 'treatments')
            ->assertJsonPath('treatments.0.clinic_appointment_id', 701)->assertJsonPath('treatments.0.state', 'draft')
            ->assertJsonPath('treatments.1.state', 'published')->assertJsonCount(22, 'services')
            ->assertJsonPath('services.0.service_snapshot.name', 'Saved service 22')
            ->assertJsonPath('confinement.discharge_note', 'Recovered');
        $this->getJson('/api/admin/pets/101/cases/702/details')->assertOk()->assertJsonPath('medical_record', null)
            ->assertJsonCount(1, 'treatments')->assertJsonCount(1, 'services')->assertJsonPath('confinement', null);
        $this->getJson('/api/admin/pets/101/cases/703/details')->assertNotFound();
        $this->getJson('/api/admin/pets/101/cases/999/details')->assertNotFound();
        $this->authenticate(30, 'staff');
        $this->getJson('/api/admin/pets/101/cases/701/details')->assertForbidden();
        $this->authenticate(10, 'customer');
        $this->getJson('/api/admin/pets/101/cases/701/details')->assertForbidden();
    }

    private function createCaseHistoryTables(): void
    {
        Schema::create('clinic_services', function (Blueprint $table) {
            $table->id(); $table->string('name');
        });
        Schema::create('clinic_charges', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_case_service_id'); $table->decimal('amount', 10, 2);
        });
        Schema::create('clinic_case_services', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id'); $table->unsignedBigInteger('clinic_service_id');
            $table->string('status'); $table->json('service_snapshot'); $table->text('notes')->nullable();
            $table->decimal('applied_price', 10, 2); $table->decimal('quantity', 10, 2)->default(1); $table->timestamp('performed_at');
        });
        Schema::create('clinic_confinements', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id'); $table->unsignedBigInteger('clinic_case_service_id');
            $table->timestamp('admitted_at'); $table->timestamp('discharged_at')->nullable(); $table->text('reason');
            $table->text('discharge_note')->nullable();
        });
    }

    private function insertPet(int $petId, string $name): void
    {
        DB::table('pets')->insert([
            'pet_id' => $petId,
            'user_id' => 10,
            'pet_name' => $name,
            'species' => 'Cat',
        ]);
    }

    private function authenticate(int $userId, string $role): void
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
