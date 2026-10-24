<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicCharge;
use App\Models\ClinicService;
use App\Models\User;
use App\Services\ClinicBillingService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicCaseServiceBillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('role');
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('species');
            $table->string('size')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->json('clinic_verified_fields')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->unsignedInteger('pet_id')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->boolean('paid')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
        });
        (require database_path('migrations/2026_10_10_000001_create_clinic_charges_table.php'))->up();
        (require database_path('migrations/2026_10_13_000001_create_clinic_services_table.php'))->up();
        (require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php'))->up();
        (require database_path('migrations/2026_10_14_000001_remove_clinic_service_type.php'))->up();
        DB::table('pets')->insert(['pet_id' => 1, 'species' => 'dog', 'weight' => 15, 'size' => 'small']);
        ClinicAppointment::create(['status' => 'in_consultation', 'pet_id' => 1]);
        $this->signIn();
    }

    private function signIn(string $subrole = 'veterinarian', int $id = 1): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $id, 'role' => $subrole === 'admin' ? 'admin' : 'staff',
            'staff_type' => $subrole === 'grooming_receptionist' ? 'grooming' : 'clinic',
            'staff_subrole' => $subrole, 'is_active' => true,
        ]);
        DB::table('users')->updateOrInsert(['user_id' => $id], $user->getAttributes());
        Sanctum::actingAs($user);
    }

    private function catalog(array $changes = []): ClinicService
    {
        return ClinicService::create(array_replace([
            'name' => 'CBC', 'category_id' => 2, 'identity_key' => hash('sha256', Str::uuid()),
            'pricing_method' => 'fixed', 'billing_unit' => 'sample', 'price' => 500, 'is_active' => true,
        ], $changes));
    }

    private function payload(ClinicService $service, array $changes = []): array
    {
        return array_replace(['clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid()], $changes);
    }

    public function test_checked_in_cases_cannot_add_services_until_consultation_starts(): void
    {
        $service = $this->catalog();
        ClinicAppointment::find(1)->update(['status' => 'checked_in']);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 0);
        ClinicAppointment::find(1)->update(['status' => 'in_consultation']);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertCreated();
    }

    public function test_manual_service_is_performed_immediately_with_notes_and_server_price(): void
    {
        $service = $this->catalog();
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$service->id)->assertOk()
            ->assertJsonPath('pricing.resolved_price', '500.00');
        $service->update(['price' => 650]);
        $payload = $this->payload($service, ['notes' => 'Blood test requested', 'applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'performed')->assertJsonPath('data.quantity', '1.00')
            ->assertJsonPath('data.notes', 'Blood test requested')->assertJsonPath('data.applied_price', '650.00')
            ->assertJsonPath('data.charge.amount', '650.00')->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 650]);
        $entry = ClinicCaseService::findOrFail($id);
        $this->assertArrayNotHasKey('type', $entry->service_snapshot);
        $this->assertNull($entry->ordered_at);
        $this->assertNotNull($entry->performed_at);
        $this->assertEquals(650, ClinicAppointment::find(1)->total_amount);
    }

    public function test_manual_api_rejects_client_status_and_quantity_and_has_no_transition_endpoint(): void
    {
        $service = $this->catalog();
        foreach (['ordered', 'performed', 'cancelled'] as $status) {
            $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, ['status' => $status]))
                ->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, ['quantity' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->patchJson('/api/admin/clinic-cases/1/services/1', ['status' => 'performed'])->assertStatus(405);
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_shot_quantity_is_validated_and_charged_once_using_the_catalogue_price(): void
    {
        $service = $this->catalog(['name' => 'X-ray — Additional Shot', 'billing_unit' => 'shot', 'price' => 350, 'category_id' => 4]);
        foreach ([null, 0, -1, 1.5, 'two'] as $quantity) {
            $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, ['quantity' => $quantity]))
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $payload = $this->payload($service, ['quantity' => 2, 'applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.quantity', '2.00')->assertJsonPath('data.charge.amount', '700.00')->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $service->update(['price' => 900]);
        $this->getJson('/api/admin/clinic-cases/1/services?scope=imaging')->assertOk()->assertJsonPath('data.0.applied_price', '350.00');
        $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 700);
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertOk();
        $this->assertEquals(0, ClinicAppointment::find(1)->total_amount);
    }

    public function test_imaging_catalogue_migration_reuses_ids_and_can_run_again_without_duplicates(): void
    {
        Schema::create('clinic_attachments', fn (Blueprint $table) => $table->id());
        DB::table('clinic_service_categories')->where('id', 4)->update(['name' => 'Imaging']);
        $service = $this->catalog(['name' => 'X-Ray - Abdominal', 'category_id' => 4, 'price' => 1500]);
        $migration = require database_path('migrations/2026_10_15_000002_configure_diagnostic_imaging.php');
        $migration->up();
        $this->assertDatabaseHas('clinic_service_categories', ['id' => 4, 'name' => 'Diagnostic Imaging']);
        $this->assertDatabaseMissing('clinic_service_categories', ['name' => 'Imaging']);
        $this->assertDatabaseHas('clinic_services', ['id' => $service->id, 'name' => 'X-ray — Abdominal', 'price' => 1950]);
        $this->assertDatabaseCount('clinic_services', 11);
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('clinic_service_categories', 5);
        $this->assertDatabaseCount('clinic_services', 11);
        $this->assertEquals(350, ClinicService::where('billing_unit', 'shot')->sole()->price);
    }

    public function test_supported_catalogue_services_only_create_service_charges(): void
    {
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->text('diagnosis')->nullable();
        });
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 1, 'diagnosis' => 'Vet-entered clinical information']);
        $medical = DB::table('clinic_records')->first();
        foreach ([['CBC', 2], ['CPV', 3], ['Consultation', 1]] as [$name, $category]) {
            $service = $this->catalog(['name' => $name, 'category_id' => $category]);
            $payload = $this->payload($service);
            $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated();
            $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        }
        $this->assertDatabaseCount('clinic_case_services', 3);
        $this->assertDatabaseCount('clinic_charges', 3);
        $this->assertEquals($medical, DB::table('clinic_records')->first());
        $this->assertEquals(1500, ClinicAppointment::find(1)->total_amount);
        $this->assertSame(['service'], ClinicCharge::distinct()->pluck('kind')->all());
        $this->assertSame(0, ClinicCharge::whereNotNull('inventory_item_id')->count());
        $this->assertSame(0, ClinicCharge::whereNotNull('vaccination_record_id')->count());
    }

    public function test_treatment_charge_scope_is_paginated_independently_with_the_full_case_total(): void
    {
        for ($i = 0; $i < 25; $i++) {
            ClinicAppointment::find(1)->charges()->create([
                'kind' => 'service', 'description' => 'Clinic service', 'quantity' => 1, 'unit_price' => 10, 'amount' => 10,
            ]);
        }
        foreach (['medicine', 'vaccine'] as $kind) ClinicAppointment::find(1)->charges()->create([
            'kind' => $kind, 'description' => $kind, 'quantity' => 1, 'unit_price' => 480, 'amount' => 480,
        ]);
        app(ClinicBillingService::class)->updateTotal(ClinicAppointment::find(1));
        $this->getJson('/api/admin/clinic-cases/1/charges?scope=treatment')->assertOk()
            ->assertJsonPath('charges.total', 2)->assertJsonPath('charges.data.0.kind', 'medicine')
            ->assertJsonPath('charges.data.1.kind', 'vaccine')->assertJsonPath('total_amount', 1210);
        $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('charges.total', 27);
    }

    public function test_type_cleanup_preserves_historical_references_prices_and_json(): void
    {
        // Reconstruct the catalogue that existed before the historical Type migration.
        DB::table('clinic_service_categories')->insert([
            ['id' => 5, 'name' => 'Procedures'], ['id' => 7, 'name' => 'Certificates & Forms'],
        ]);
        $migration = require database_path('migrations/2026_10_14_000001_remove_clinic_service_type.php');
        $migration->down();
        DB::table('clinic_service_categories')->where('id', 3)->update(['name' => 'Diagnostics']);
        DB::table('clinic_service_categories')->where('id', 6)->update(['name' => 'Boarding & Lodging']);
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id(); $table->string('item_name'); $table->string('category');
        });
        DB::table('inventory_items')->insert([
            ['item_name' => 'Anti Rabies', 'category' => 'vaccine'],
            ['item_name' => 'Cefalexin', 'category' => 'medicine'],
        ]);
        $cbc = $this->catalog();
        $duplicate = $this->catalog();
        $cpv = $this->catalog(['name' => 'CPV']);
        $certificate = $this->catalog(['name' => 'Anti Rabies', 'category_id' => 7]);
        $medicine = $this->catalog(['name' => 'Triamcinolone Injectable', 'category_id' => 5]);
        $antibiotic = $this->catalog(['name' => 'Cefalexin', 'category_id' => 5]);
        $boarding = $this->catalog(['name' => 'Daily boarding', 'category_id' => 6]);
        $imaging = $this->catalog(['name' => 'X-Ray', 'category_id' => 4]);
        DB::table('clinic_services')->where('id', $cpv->id)->update(['type' => 'Test Kit']);
        DB::table('clinic_services')->where('id', $duplicate->id)->update(['type' => 'Send Out']);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($medicine))->json('data.id');
        $entry = ClinicCaseService::findOrFail($id);
        $entry->forceFill(['service_snapshot' => [...$entry->service_snapshot, 'type' => 'In House']])->save();
        $beforeEntry = $entry->getAttributes();
        $beforeCharge = ClinicCharge::first()->getAttributes();
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $migration->up();
        $this->assertFalse(Schema::hasColumn('clinic_services', 'type'));
        $this->assertDatabaseCount('clinic_services', 8);
        foreach ([$duplicate, $medicine, $antibiotic, $boarding] as $obsolete) {
            $this->assertFalse($obsolete->fresh()->is_active);
        }
        foreach ([$cbc, $cpv, $certificate, $imaging] as $active) $this->assertTrue($active->fresh()->is_active);
        $this->assertSame('Diagnostic Tests', $cpv->fresh()->category->name);
        $this->assertSame('Certificates & Forms', $certificate->fresh()->category->name);
        $this->assertSame($beforeEntry, $entry->fresh()->getAttributes());
        $this->assertSame($beforeCharge, ClinicCharge::first()->getAttributes());
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()
            ->assertJsonPath('data.0.service_snapshot.type', 'In House')->assertJsonPath('data.0.applied_price', '500.00');
        $this->getJson('/api/admin/clinic-service-options?q=Triamcinolone')->assertOk()->assertJsonPath('total', 0);
        $this->signIn('admin');
        $this->postJson('/api/admin/clinic-services', [
            'name' => 'CBC', 'category_id' => 2, 'pricing_method' => 'fixed', 'price' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_retired_category_cleanup_deletes_catalogue_rows_and_preserves_paid_history(): void
    {
        DB::table('clinic_service_categories')->insert([
            ['id' => 5, 'name' => 'Procedures'], ['id' => 7, 'name' => 'Certificates & Forms'],
        ]);
        Schema::create('clinic_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_case_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
        });
        $remaining = $this->catalog();
        $remainingId = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($remaining))
            ->assertCreated()->json('data.id');
        $remainingBefore = $remaining->fresh()->getAttributes();
        $histories = [];
        foreach ([5, 7] as $category) {
            $retired = $this->catalog(['name' => 'Retired '.$category, 'category_id' => $category]);
            $this->catalog(['name' => 'Inactive '.$category, 'category_id' => $category, 'is_active' => false]);
            // Reproduce pre-cleanup billing without adding retired services through the current API.
            $entry = new ClinicCaseService;
            $entry->forceFill([
                'clinic_appointment_id' => 1, 'clinic_service_id' => $retired->id,
                'request_key' => (string) Str::uuid(), 'status' => 'performed', 'quantity' => 1,
                'applied_price' => 500, 'performed_at' => now(),
                'service_snapshot' => ['name' => $retired->name, 'category' => $retired->category->name],
            ])->save();
            $charge = new ClinicCharge;
            $charge->forceFill([
                'clinic_appointment_id' => 1, 'clinic_service_id' => $retired->id, 'clinic_case_service_id' => $entry->id,
                'kind' => 'service', 'description' => $retired->name, 'quantity' => 1, 'unit_price' => 500, 'amount' => 500,
            ])->save();
            DB::table('clinic_attachments')->insert(['clinic_case_service_id' => $entry->id, 'path' => 'history.pdf']);
            $histories[] = [$entry->id, $entry->fresh()->getAttributes(), $charge->id, $charge->fresh()->getAttributes()];
        }
        app(ClinicBillingService::class)->updateTotal(ClinicAppointment::find(1));
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $caseBefore = ClinicAppointment::find(1)->getAttributes();
        $migration = require database_path('migrations/2026_10_15_000003_remove_retired_clinic_service_categories.php');
        $migration->up();
        $migration->up(); // Safe if a deployment retries the data cleanup.
        $this->assertDatabaseCount('clinic_service_categories', 5);
        $this->assertDatabaseCount('clinic_services', 1);
        $this->assertSame($remainingBefore, $remaining->fresh()->getAttributes());
        $this->assertSame($caseBefore, ClinicAppointment::find(1)->getAttributes());
        $this->assertDatabaseHas('clinic_case_services', ['id' => $remainingId, 'clinic_service_id' => $remaining->id]);
        foreach ($histories as [$entryId, $beforeEntry, $chargeId, $beforeCharge]) {
            $this->assertSame([...$beforeEntry, 'clinic_service_id' => null], ClinicCaseService::find($entryId)->getAttributes());
            $this->assertSame([...$beforeCharge, 'clinic_service_id' => null], ClinicCharge::find($chargeId)->getAttributes());
            $this->assertDatabaseHas('clinic_attachments', ['clinic_case_service_id' => $entryId, 'path' => 'history.pdf']);
        }
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 1500);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        try {
            $remaining->delete();
            $this->fail('Remaining service history must still be protected by its foreign key.');
        } catch (QueryException) {
            $this->assertDatabaseHas('clinic_services', ['id' => $remaining->id]);
        }
        $this->signIn('admin');
        ClinicAppointment::find(1)->update(['status' => 'in_consultation', 'paid' => false]);
        $this->patchJson('/api/admin/clinic-cases/1/charges/'.$histories[0][2], ['unit_price' => 0])->assertUnprocessable();
    }

    public function test_retired_category_cleanup_on_a_fresh_catalogue_keeps_five_categories(): void
    {
        (require database_path('migrations/2026_10_15_000003_remove_retired_clinic_service_categories.php'))->up();
        $this->assertSame(\App\Models\ClinicServiceCategory::SUPPORTED_NAMES,
            DB::table('clinic_service_categories')->orderBy('id')->pluck('name')->all());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_removing_manual_service_deletes_only_its_charge_and_recalculates_total(): void
    {
        $service = $this->catalog();
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertCreated()->json('data.id');
        $otherId = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertCreated()->json('data.id');
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertOk()->assertJsonPath('message', 'Service removed.');
        $this->assertDatabaseMissing('clinic_case_services', ['id' => $id]);
        $this->assertDatabaseMissing('clinic_charges', ['clinic_case_service_id' => $id]);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $otherId, 'amount' => 500]);
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 500);
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertNotFound();
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$otherId)->assertOk();
        $this->assertEquals(0, ClinicAppointment::find(1)->total_amount);
    }

    public function test_paid_archived_and_locked_cases_cannot_remove_a_service(): void
    {
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertCreated()->json('data.id');
        $this->signIn('admin');
        foreach ([['paid' => true], ['archived_at' => now()], ['status' => 'completed'], ['status' => 'cancelled'],
            ['status' => 'no_show'], ['status' => 'expired'], ['status' => 'pending']] as $lock) {
            ClinicAppointment::find(1)->update(['paid' => false, 'archived_at' => null, 'status' => 'in_consultation', ...$lock]);
            $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable();
            $this->assertDatabaseHas('clinic_case_services', ['id' => $id]);
            $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 500]);
            $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
        }
        ClinicAppointment::find(1)->update(['status' => 'for_payment']);
        $this->signIn();
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable();
        $this->signIn('admin');
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertOk();
    }

    public function test_removal_rolls_back_both_records_when_total_update_fails(): void
    {
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertCreated()->json('data.id');
        $this->mock(ClinicBillingService::class)->shouldReceive('updateTotal')->once()
            ->andThrow(ValidationException::withMessages(['charges' => 'Unable to update total.']));
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable();
        $this->assertDatabaseHas('clinic_case_services', ['id' => $id]);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_fixed_service_generates_one_charge_and_retries_after_payment_do_not_duplicate_it(): void
    {
        $payload = $this->payload($this->catalog(), changes: ['applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.applied_price', '500.00')->assertJsonPath('data.charge.amount', '500.00')->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_case_services', 1);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
        $this->assertSame(1, ClinicCaseService::find($id)->performed_by_user_id);
    }

    public function test_catalog_changes_preserve_completed_case_charge_and_clinical_snapshots(): void
    {
        $service = $this->catalog();
        $payload = $this->payload($service);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $snapshot = ClinicCaseService::find($id)->service_snapshot;
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $service->update(['name' => 'New CBC', 'price' => 900, 'billing_unit' => 'pet', 'is_active' => false]);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk();
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()
            ->assertJsonPath('data.0.service_snapshot.name', 'CBC')->assertJsonPath('data.0.applied_price', '500.00');
        $this->assertSame($snapshot, ClinicCaseService::find($id)->service_snapshot);
        $this->assertDatabaseHas('clinic_charges', ['description' => 'CBC', 'unit_price' => 500, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_tiered_price_uses_only_current_case_weight_despite_conflicting_verified_profile_size(): void
    {
        $service = $this->catalog(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size', 'tiers' => [
            ['size' => 'small', 'price' => 300], ['size' => 'medium', 'price' => 600], ['size' => 'large', 'price' => 800],
        ]]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 30]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))
            ->assertCreated()->assertJsonPath('data.applied_price', '800.00')->assertJsonPath('data.service_snapshot.pet_size', 'large');
        DB::table('pets')->where('pet_id', 1)->update(['clinic_verified_fields' => json_encode(['size'])]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))
            ->assertCreated()->assertJsonPath('data.applied_price', '800.00')->assertJsonPath('data.service_snapshot.pet_size', 'large');
        DB::table('pets')->where('pet_id', 1)->update(['size' => 'extra_large']);
        DB::table('clinic_vitals')->where('clinic_appointment_id', 1)->update(['weight_kg' => 70]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_case_services', 2);
        $this->assertDatabaseCount('clinic_charges', 2);
    }

    public function test_range_and_starting_at_require_a_final_price_within_catalog_rules(): void
    {
        foreach (['range', 'starting_at'] as $method) {
            $service = $this->catalog(['pricing_method' => $method, 'price' => 500, 'min_price' => 500, 'max_price' => 700]);
            foreach ([null, 499.99, 100000.01, '500.001', ...($method === 'range' ? [700.01] : [])] as $price) {
                $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, changes: ['applied_price' => $price]))
                    ->assertUnprocessable()->assertJsonValidationErrors('applied_price');
            }
            $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, ['applied_price' => 650]))
                ->assertCreated()->assertJsonPath('data.charge.amount', '650.00');
        }
        $this->assertDatabaseCount('clinic_charges', 2);
    }

    public function test_service_generated_prices_and_quantities_are_read_only_for_every_billing_role(): void
    {
        $payload = $this->payload($this->catalog());
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $before = ClinicCaseService::find($id)->getAttributes();
        foreach (['veterinarian', 'clinic_receptionist', 'admin'] as $subrole) {
            $this->signIn($subrole);
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk();
            foreach ([['unit_price' => 400], ['unit_price' => 600], ['unit_price' => 0], ['quantity' => 2],
                ['amount' => 0, 'clinic_case_service_id' => null]] as $change) {
                $response = $this->patchJson('/api/admin/clinic-cases/1/charges/1', $change);
                if ($subrole === 'clinic_receptionist') $response->assertForbidden();
                else $response->assertUnprocessable()->assertJsonValidationErrors('charges');
            }
            $this->assertSame($before, ClinicCaseService::find($id)->getAttributes());
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'paid' => false, 'total_amount' => 500]);
        }
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.charge.amount', '500.00');
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_unlinked_medicine_vaccine_and_legacy_charge_behavior_is_preserved(): void
    {
        $this->signIn('veterinarian');
        foreach (['medicine', 'vaccine', 'existing'] as $kind) {
            $id = DB::table('clinic_charges')->insertGetId([
                'clinic_appointment_id' => 1, 'kind' => $kind, 'description' => $kind,
                'quantity' => 1, 'unit_price' => 500, 'amount' => 500,
            ]);
            $this->patchJson('/api/admin/clinic-cases/1/charges/'.$id, ['unit_price' => 600, 'quantity' => 2])
                ->assertOk()->assertJsonPath('data.amount', '1200.00');
        }
        $this->assertEquals(3600, ClinicAppointment::find(1)->total_amount);
    }

    public function test_receptionist_can_view_billing_but_cannot_edit_even_an_unlinked_charge(): void
    {
        $id = DB::table('clinic_charges')->insertGetId([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Exam',
            'quantity' => 1, 'unit_price' => 500, 'amount' => 500,
        ]);
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('charges.data.0.amount', '500.00');
        $this->patchJson('/api/admin/clinic-cases/1/charges/'.$id, ['unit_price' => 1])->assertForbidden();
        try {
            app(ClinicBillingService::class)->updateCharge(1, $id, ['unit_price' => 1], auth()->user());
            $this->fail('The billing service must reject receptionist edits.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('clinic_charges', ['id' => $id, 'unit_price' => 500, 'amount' => 500]);
        foreach (['veterinarian', 'admin'] as $subrole) {
            $this->signIn($subrole);
            $this->patchJson('/api/admin/clinic-cases/1/charges/'.$id, ['unit_price' => 600])
                ->assertOk()->assertJsonPath('data.amount', '600.00');
        }
    }

    public function test_starting_at_accepts_prices_above_grooming_safety_caps(): void
    {
        $service = $this->catalog(['pricing_method' => 'starting_at', 'price' => 1000]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, changes: ['applied_price' => 1800]))
            ->assertCreated()->assertJsonPath('data.applied_price', '1800.00');
    }

    public function test_range_boundaries_are_valid_and_resolved_variable_prices_cannot_be_changed_in_billing(): void
    {
        $service = $this->catalog(['pricing_method' => 'range', 'price' => null, 'min_price' => 800, 'max_price' => 1500]);
        foreach ([800, 1500] as $price) {
            $chargeId = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service, changes: ['applied_price' => $price]))
                ->assertCreated()->json('data.charge.id');
            $this->patchJson('/api/admin/clinic-cases/1/charges/'.$chargeId, ['unit_price' => 1200])->assertUnprocessable();
            $this->assertDatabaseHas('clinic_charges', ['id' => $chargeId, 'unit_price' => $price, 'amount' => $price]);
        }
    }

    public function test_tiered_without_valid_saved_case_weight_cannot_use_profile_weight_or_size(): void
    {
        $service = $this->catalog(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size',
            'tiers' => [['size' => 'small', 'price' => 300], ['size' => 'medium', 'price' => 600]]]);
        DB::table('pets')->where('pet_id', 1)->update(['clinic_verified_fields' => json_encode(['size'])]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        foreach ([null, 0, -1, 156] as $weight) {
            DB::table('clinic_vitals')->updateOrInsert(['clinic_appointment_id' => 1], ['weight_kg' => $weight]);
            $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        }
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_tiered_snapshot_does_not_change_when_case_weight_species_tiers_or_method_change(): void
    {
        $service = $this->catalog(['pricing_method' => 'tiered', 'price' => null, 'tier_basis' => 'pet_size',
            'tiers' => [['size' => 'medium', 'price' => 600]]]);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 18.40]);
        $payload = $this->payload($service, changes: ['applied_price' => 1]);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
            ->assertJsonPath('data.applied_price', '600.00')->json('data.id');
        $snapshot = ClinicCaseService::find($id)->service_snapshot;
        $this->assertEquals(18.40, $snapshot['pet_weight_kg']);
        DB::table('clinic_vitals')->where('clinic_appointment_id', 1)->update(['weight_kg' => 3]);
        DB::table('pets')->where('pet_id', 1)->update(['species' => 'cat', 'weight' => 4, 'size' => 'small']);
        $service->update(['tiers' => [['size' => 'small', 'price' => 300]], 'pricing_method' => 'fixed', 'price' => 900, 'is_active' => false]);
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.charge.unit_price', '600.00');
        $this->assertSame($snapshot, ClinicCaseService::find($id)->service_snapshot);
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_active_catalogue_lookup_and_server_pricing_preview_preserve_clinical_permissions(): void
    {
        $fixed = $this->catalog();
        $this->catalog(['is_active' => false]);
        $this->getJson('/api/admin/clinic-service-options?status=all')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/clinic-services')->assertOk();
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$fixed->id)->assertOk()
            ->assertJsonPath('pricing.requires_final_price', false)->assertJsonPath('pricing.resolved_price', '500.00');
        $tiered = $this->catalog(['pricing_method' => 'tiered', 'tier_basis' => 'pet_size', 'tiers' => [['size' => 'medium', 'price' => 600]]]);
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$tiered->id)->assertOk()
            ->assertJsonPath('pricing.error', "Record the pet's weight in Vitals before performing this service.");
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 18.4]);
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$tiered->id)->assertOk()
            ->assertJsonPath('pricing.pet_size', 'medium')->assertJsonPath('pricing.resolved_price', '600.00');
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-service-options')->assertForbidden();
        $this->getJson('/api/admin/clinic-cases/1/service-pricing/'.$fixed->id)->assertForbidden();
    }

    public function test_receptionist_can_view_case_services_but_cannot_mutate_them(): void
    {
        $payload = $this->payload($this->catalog());
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 500, 'payment_method' => 'cash'])->assertUnprocessable();
        foreach (['clinic_receptionist', 'grooming_receptionist'] as $subrole) {
            $this->signIn($subrole);
            $this->getJson('/api/admin/clinic-cases/1/services')->assertOk();
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk();
            $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertForbidden();
            $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertForbidden();
            $this->postJson('/api/admin/clinic-appointments/1/record', ['diagnosis' => 'Unauthorized'])->assertForbidden();
            $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['unit_price' => 0])->assertForbidden();
        }
    }

    public function test_completed_cases_reject_new_services_and_financial_edits(): void
    {
        $payload = $this->payload($this->catalog());
        $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated();
        ClinicAppointment::find(1)->update(['status' => 'completed', 'paid' => true]);
        $this->signIn('admin');
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->patchJson('/api/admin/clinic-cases/1/charges/1', ['unit_price' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_inactive_services_request_key_collisions_and_cross_case_removals_are_rejected(): void
    {
        $service = $this->catalog();
        $payload = $this->payload($service);
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->json('data.id');
        ClinicAppointment::create(['status' => 'checked_in', 'pet_id' => 1]);
        $this->postJson('/api/admin/clinic-cases/2/services', $payload)->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['clinic_service_id' => $this->catalog()->id]))->assertUnprocessable();
        $this->deleteJson('/api/admin/clinic-cases/2/services/'.$id)->assertNotFound();
        $this->patchJson('/api/admin/clinic-cases/2/charges/1', ['unit_price' => 0])->assertNotFound();
        $service->update(['is_active' => false]);
        $this->postJson('/api/admin/clinic-cases/1/services', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 1);
    }

    public function test_database_prevents_two_charges_for_the_same_service_occurrence(): void
    {
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertCreated();
        $duplicate = ClinicCharge::first()->getAttributes();
        unset($duplicate['id']);
        $this->expectException(QueryException::class);
        DB::table('clinic_charges')->insert($duplicate);
    }

    public function test_case_total_overflow_rolls_back_an_otherwise_valid_charge(): void
    {
        DB::table('clinic_charges')->insert([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Existing charges',
            'quantity' => 1, 'unit_price' => 99999500, 'amount' => 99999500,
        ]);
        ClinicAppointment::find(1)->update(['total_amount' => 99999500]);
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_case_services', 0);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertEquals(99999500, ClinicAppointment::find(1)->total_amount);
    }

    public function test_foreign_keys_protect_service_history_from_catalog_or_case_deletion(): void
    {
        $service = $this->catalog();
        $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($service))->assertCreated();
        foreach ([fn () => $service->delete(), fn () => ClinicAppointment::find(1)->delete(), fn () => ClinicCaseService::first()->delete()] as $delete) {
            try {
                $delete();
                $this->fail('A referenced service occurrence must not be deleted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('clinic_case_services', 1);
                $this->assertDatabaseCount('clinic_charges', 1);
            }
        }
    }

    public function test_service_history_survives_removing_a_financial_line(): void
    {
        $id = $this->postJson('/api/admin/clinic-cases/1/services', $this->payload($this->catalog()))->json('data.id');
        ClinicCharge::first()->delete();
        $this->assertDatabaseHas('clinic_case_services', ['id' => $id, 'status' => 'performed', 'applied_price' => 500]);
    }

    public function test_new_routes_use_separate_clinical_billing_and_payment_middleware(): void
    {
        foreach ([
            ['POST', 'api/admin/clinic-cases/{id}/services', 'clinic:clinical'],
            ['DELETE', 'api/admin/clinic-cases/{id}/services/{caseServiceId}', 'clinic:clinical'],
            ['GET', 'api/admin/clinic-cases/{id}/charges', 'clinic:billing_view'],
            ['PATCH', 'api/admin/clinic-cases/{id}/charges/{chargeId}', 'clinic:billing_edit'],
            ['POST', 'api/admin/clinic-appointments/{id}/pay', 'clinic:payment'],
            ['GET', 'api/admin/clinic-services/options', 'clinic:services_view'],
            ['GET', 'api/admin/clinic-services', 'clinic:services_view'],
            ['POST', 'api/admin/clinic-services', 'clinic:services_manage'],
            ['PUT', 'api/admin/clinic-services/{clinicService}', 'clinic:services_manage'],
            ['PATCH', 'api/admin/clinic-services/{clinicService}/status', 'clinic:services_manage'],
        ] as [$method, $uri, $permission]) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(
                fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true),
            );
            $this->assertContains($permission, $route->gatherMiddleware());
            $this->assertContains('clinic:access', $route->gatherMiddleware());
        }
    }

    public function test_migration_can_be_rolled_back_without_changing_legacy_charges(): void
    {
        DB::table('clinic_charges')->insert([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Legacy fee',
            'quantity' => 1, 'unit_price' => 250, 'amount' => 250,
        ]);
        $migration = require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('clinic_case_services'));
        $this->assertDatabaseHas('clinic_charges', ['description' => 'Legacy fee', 'amount' => 250]);
        $migration->up();
        $this->assertDatabaseHas('clinic_charges', ['description' => 'Legacy fee', 'clinic_case_service_id' => null]);
    }
}
