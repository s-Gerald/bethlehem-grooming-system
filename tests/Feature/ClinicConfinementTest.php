<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicConfinement;
use App\Models\ClinicService;
use App\Models\ClinicServiceCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicConfinementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role'); $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable(); $table->string('first_name')->nullable();
            $table->string('last_name')->nullable(); $table->boolean('is_active')->default(true);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id'); $table->string('pet_name'); $table->string('species');
            $table->decimal('weight', 5, 2)->nullable(); $table->string('size')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id(); $table->string('appointment_reference'); $table->string('status');
            $table->string('case_type')->default('consultation'); $table->unsignedInteger('pet_id');
            $table->unsignedInteger('user_id')->nullable(); $table->date('appointment_date')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0); $table->boolean('paid')->default(false);
            $table->timestamp('archived_at')->nullable(); $table->timestamp('consultation_finished_at')->nullable(); $table->timestamps();
        });
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id')->unique();
            $table->unsignedInteger('primary_veterinarian_id')->nullable(); $table->string('primary_veterinarian_name')->nullable();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id')->unique(); $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable(); $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable(); $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id'); $table->string('type'); $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('clinic_appointment_id')->nullable(); $table->text('message');
            $table->boolean('is_read')->default(false); $table->timestamp('created_at')->nullable();
        });
        Schema::create('clinic_attachments', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('clinic_record_id'); });
        foreach (['2026_10_10_000001_create_clinic_charges_table.php', '2026_10_13_000001_create_clinic_services_table.php',
            '2026_10_13_000002_create_clinic_case_services_table.php', '2026_10_13_000004_create_clinic_confinements.php',
            '2026_10_14_000001_remove_clinic_service_type.php', '2026_10_14_000002_configure_confinement_charges.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->signIn();
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi', 'species' => 'dog', 'weight' => 1, 'size' => 'small']);
        ClinicAppointment::create(['appointment_reference' => 'CL-1', 'status' => 'in_consultation', 'pet_id' => 1]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 1, 'primary_veterinarian_id' => 1, 'primary_veterinarian_name' => 'Attending Vet']);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 18.4, 'temperature_c' => 38,
            'heart_rate_bpm' => 90, 'respiratory_rate_bpm' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signIn(string $subrole = 'veterinarian'): void
    {
        $user = new User;
        $user->forceFill(['user_id' => 1, 'role' => $subrole === 'admin' ? 'admin' : 'staff', 'staff_type' => $subrole === 'grooming_receptionist' ? 'grooming' : 'clinic',
            'staff_subrole' => $subrole, 'is_active' => true, 'first_name' => 'Attending', 'last_name' => 'Vet']);
        DB::table('users')->updateOrInsert(['user_id' => 1], $user->getAttributes());
        Sanctum::actingAs($user);
    }

    private function service(array $changes = []): ClinicService
    {
        return ClinicService::create(array_replace(['name' => 'Confinement',
            'category_id' => ClinicServiceCategory::where('name', 'Confinement')->value('id'),
            'identity_key' => hash('sha256', Str::uuid()), 'pricing_method' => 'tiered', 'billing_unit' => 'day',
            'charge_behavior' => 'base_confinement', 'tier_basis' => 'weight_kg',
            'tiers' => array_map(fn ($size, $max) => ['size' => $size, 'price' => 500, 'max_weight_kg' => $max],
                ['small', 'medium', 'large', 'extra_large'], [10, 25, 40, null]),
            'price' => null, 'is_active' => true], $changes));
    }

    private function admit(?ClinicService $service = null): array
    {
        $service ??= $this->service();
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Observation'];
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertCreated();
        return $payload;
    }

    public function test_carry_over_confined_case_is_discoverable_without_appearing_in_active_cases(): void
    {
        $this->admit();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases')->assertJsonPath('confined_count', 1);
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.status', 'in_consultation');
        $this->getJson('/api/admin/pets/1/clinic-case')->assertOk()->assertJsonPath('case.id', 1)
            ->assertJsonPath('case.confinement.discharged_at', null);

        ClinicAppointment::find(1)->update(['status' => 'completed']);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases')->assertJsonPath('confined_count', 0);
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/pets/1/clinic-case')->assertOk()->assertJsonPath('case', null);
    }

    public function test_active_list_omits_workspace_data_and_details_remain_available_on_demand(): void
    {
        $controller = new \App\Http\Controllers\AdminClinicController;
        $full = $controller->formatAppointment(ClinicAppointment::findOrFail(1));
        DB::enableQueryLog(); DB::flushQueryLog();
        $list = $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('cases.0.pet.name', 'Mochi');
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $row = $list->json('cases.0');
        $this->assertArrayNotHasKey('record', $row);
        $this->assertArrayNotHasKey('vitals', $row);
        $this->assertArrayNotHasKey('charges', $row);
        $this->assertArrayNotHasKey('medical_conditions', $row['pet']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/select .* from ["`](clinic_records|clinic_vitals|clinic_attachments|clinic_charges)["`]/i', $query['query']);
        }
        $this->assertLessThan(strlen(json_encode($full)), strlen(json_encode($row)));
        $this->getJson('/api/admin/clinic-cases/1')->assertOk()
            ->assertJsonPath('case.vitals.weight_kg', '18.40')
            ->assertJsonPath('case.record.primary_veterinarian_name', $full['record']['primary_veterinarian_name']);
        $this->signIn('grooming_receptionist');
        $this->getJson('/api/admin/clinic-cases/1')->assertOk()
            ->assertJsonPath('case.vitals.weight_kg', '18.40')
            ->assertJsonPath('case.record.primary_veterinarian_name', $full['record']['primary_veterinarian_name']);
        fwrite(STDOUT, "\nClinic fixture list=".strlen(json_encode($row))." bytes; full=".strlen(json_encode($full))." bytes; list queries=".count($queries)."\n");
    }

    public function test_admission_links_one_ordered_service_to_the_same_case_and_retries_are_idempotent(): void
    {
        $payload = $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement', [...$payload, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_appointments', 1); $this->assertDatabaseCount('clinic_confinements', 1);
        $this->assertDatabaseCount('clinic_case_services', 1); $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'in_consultation']);
        $this->assertDatabaseHas('clinic_case_services', ['status' => 'ordered']);
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('data.attending_veterinarian_name', 'Attending Vet');
    }

    public function test_admission_requires_ongoing_case_primary_veterinarian_and_appropriate_catalogue_configuration(): void
    {
        $service = $this->service();
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Observation'];
        foreach (['checked_in', 'for_payment', 'completed'] as $status) {
            ClinicAppointment::find(1)->update(['status' => $status]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
        }
        ClinicAppointment::find(1)->update(['status' => 'in_consultation']);
        foreach (['paid' => true, 'archived_at' => now()] as $field => $value) {
            ClinicAppointment::find(1)->update([$field => $value]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
            ClinicAppointment::find(1)->update([$field => $field === 'paid' ? false : null]);
        }
        DB::table('clinic_records')->update(['primary_veterinarian_id' => null]);
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable()->assertJsonValidationErrors('primary_veterinarian_id');
        DB::table('clinic_records')->update(['primary_veterinarian_id' => 1]);
        foreach ([['billing_unit' => 'sample'], ['billing_unit' => 'day', 'is_active' => false],
            ['is_active' => true, 'category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id')]] as $change) {
            $service->update($change);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable()->assertJsonValidationErrors('confinement');
        }
        $this->assertDatabaseCount('clinic_confinements', 0); $this->assertDatabaseCount('clinic_case_services', 0);
    }

    public function test_monitoring_is_append_only_chronological_idempotent_and_does_not_overwrite_vitals(): void
    {
        $this->admit(); $before = DB::table('clinic_vitals')->first();
        $payload = ['request_key' => (string) Str::uuid(), 'temperature_c' => 39.1, 'notes' => 'Alert'];
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertOk();
        Carbon::setTestNow(now()->addHour());
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid(), 'heart_rate_bpm' => 95])->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk()->assertJsonPath('data.0.notes', 'Alert')->assertJsonPath('data.1.heart_rate_bpm', 95);
        $this->assertEquals($before, DB::table('clinic_vitals')->first()); $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_receptionist_can_view_but_cannot_admit_monitor_or_discharge_and_confined_cases_cannot_be_paid(): void
    {
        $this->admit();
        foreach (['clinic_receptionist', 'grooming_receptionist'] as $subrole) {
            $this->signIn($subrole);
            $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk();
            $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk();
            foreach (['', '/observations', '/discharge'] as $suffix) $this->postJson('/api/admin/clinic-cases/1/confinement'.$suffix)->assertForbidden();
        }
        $this->signIn();
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 0, 'payment_method' => 'cash'])->assertUnprocessable();
    }

    public function test_normal_finish_and_direct_confinement_service_mutations_are_blocked(): void
    {
        $this->admit();
        foreach (['/api/admin/clinic-cases/1/finish', '/api/admin/clinic-appointments/1/finish-consultation'] as $url)
            $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('status');
        $id = ClinicCaseService::first()->id;
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable()->assertJsonValidationErrors('services');
        $entry = ClinicCaseService::findOrFail($id);
        $this->postJson('/api/admin/clinic-cases/1/services', ['clinic_service_id' => $entry->clinic_service_id,
            'request_key' => $entry->request_key])->assertUnprocessable();
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame('ordered', $entry->fresh()->status);
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_discharged_confinement_service_cannot_be_removed_through_services_tab(): void
    {
        $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $id = ClinicCaseService::first()->id;
        $this->signIn('admin');
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable()->assertJsonValidationErrors('services');
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('data.0.confinement_exists', true);
        $this->assertDatabaseHas('clinic_case_services', ['id' => $id, 'status' => 'performed']);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
        $this->assertNotNull(ClinicConfinement::first()->discharged_at);
    }

    public function test_discharge_finalizes_one_service_one_charge_and_one_payment_due_transition(): void
    {
        $this->admit(); Carbon::setTestNow(now()->addHours(6));
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['applied_price' => 1])->assertOk()
            ->assertJsonPath('data.case_service.applied_price', '500.00')->assertJsonPath('data.case_service.quantity', '1.00');
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->assertDatabaseCount('clinic_charges', 1); $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'total_amount' => 500]);
        $this->assertNotNull(ClinicConfinement::first()->discharged_at);
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid(), 'notes' => 'Late'])->assertUnprocessable();
    }

    public function test_exact_elapsed_duration_rules(): void
    {
        $rule = app(\App\Services\ConfinementBillingDuration::class); $start = now();
        foreach ([0 => 1, 6 * 3600 => 1, 86400 => 1, 86460 => 2, 172800 => 2] as $seconds => $days)
            $this->assertSame($days, $rule->billableDays($start, $start->copy()->addSeconds($seconds)));
    }

    public function test_day_quantity_is_derived_by_server_and_cannot_be_supplied_by_client(): void
    {
        $this->admit(); Carbon::setTestNow(now()->addDay()->addMinute());
        $this->getJson('/api/admin/clinic-cases/1/confinement/discharge-preview')->assertOk()->assertJsonPath('quantity', 2);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['quantity' => 1])->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk()->assertJsonPath('data.case_service.charge.amount', '1000.00');
    }

    public function test_admission_preview_uses_live_weight_without_saving_it(): void
    {
        $this->service();
        DB::table('clinic_vitals')->update(['weight_kg' => null]);
        $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview?weight_kg=18.4')->assertOk()
            ->assertJsonPath('pricing.pet_size', 'medium')->assertJsonPath('pricing.resolved_price', '500.00');
        $this->assertDatabaseHas('clinic_vitals', ['clinic_appointment_id' => 1, 'weight_kg' => null]);
        $this->assertDatabaseCount('clinic_confinements', 0);
        $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview?weight_kg=0')
            ->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        DB::table('clinic_vitals')->update(['weight_kg' => 18.4]);
        $response = $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview?weight_kg=')->assertOk();
        $this->assertNotNull($response->json('pricing.error'), 'A cleared live weight does not fall back to saved weight');
    }

    public function test_automatic_base_selection_weight_resolution_and_historical_prices(): void
    {
        $service = $this->service();
        $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview')->assertOk()
            ->assertJsonPath('data.id', $service->id)->assertJsonPath('pricing.pet_size', 'medium');
        DB::table('clinic_vitals')->update(['weight_kg' => null]);
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Sample'];
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
        DB::table('clinic_vitals')->update(['weight_kg' => 18.4]);
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertCreated();
        $snapshot = ClinicCaseService::first()->service_snapshot;
        $this->assertSame('medium', $snapshot['pet_size']);
        $this->assertEquals(18.4, $snapshot['pet_weight_kg']);
        DB::table('clinic_vitals')->update(['weight_kg' => 50]);
        $service->update(['tiers' => array_map(fn ($size) => ['size' => $size, 'price' => 650], ['small', 'medium', 'large', 'extra_large']), 'is_active' => false]);
        $this->service(['name' => 'Replacement base']);
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()
            ->assertJsonPath('pricing.resolved_price', '500.00')->assertJsonPath('pricing.pet_size', 'medium');
        $this->getJson('/api/admin/clinic-cases/1/confinement/discharge-preview')->assertOk()
            ->assertJsonPath('pricing.resolved_price', '500.00')->assertJsonPath('pricing.pet_weight_kg', '18.40');
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk()
            ->assertJsonPath('data.case_service.clinic_service_id', $service->id)
            ->assertJsonPath('data.case_service.applied_price', '500.00')->assertJsonPath('data.case_service.service_snapshot.pet_size', 'medium');
        $this->assertSame($snapshot, ClinicCaseService::first()->service_snapshot);
        $service->update(['pricing_method' => 'fixed', 'price' => 900, 'name' => 'Renamed']);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->assertSame($snapshot, ClinicCaseService::first()->service_snapshot);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 500]);
    }

    public function test_clinic_cutoffs_are_inclusive_continuous_and_independent_of_species(): void
    {
        $rates = ['small' => 101, 'medium' => 202, 'large' => 303, 'extra_large' => 404];
        $this->service(['tiers' => array_map(fn ($size, $max) => ['size' => $size, 'price' => $rates[$size], 'max_weight_kg' => $max],
            ['small', 'medium', 'large', 'extra_large'], [10, 25, 40, null])]);
        foreach (['dog', 'cat'] as $species) {
            DB::table('pets')->update(['species' => $species]);
            foreach (['0.01' => 'small', '10' => 'small', '10.01' => 'medium', '25' => 'medium',
                '25.01' => 'large', '40' => 'large', '40.01' => 'extra_large', '155.6' => 'extra_large'] as $weight => $size) {
                DB::table('clinic_vitals')->update(['weight_kg' => $weight]);
                $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview')->assertOk()->assertJsonPath('pricing.pet_size', $size)
                    ->assertJsonPath('pricing.resolved_price', number_format($rates[$size], 2, '.', ''));
            }
        }
        foreach ([null, 0, -1] as $weight) {
            DB::table('clinic_vitals')->update(['weight_kg' => $weight]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', ['request_key' => (string) Str::uuid(), 'reason' => 'Observation'])
                ->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        }
        $this->assertDatabaseCount('clinic_confinements', 0);
    }

    public function test_upgrade_snapshots_legacy_active_rates_once_without_changing_catalogue_or_billing(): void
    {
        $service = $this->service(); $this->admit($service);
        $entry = ClinicCaseService::first();
        $entry->forceFill(['applied_price' => null, 'service_snapshot' => null])->save();
        $service->update(['tier_basis' => 'pet_size', 'tiers' => array_map(fn ($size) => ['size' => $size, 'price' => 650],
            ['small', 'medium', 'large', 'extra_large'])]);
        $migration = require database_path('migrations/2026_10_15_000001_snapshot_active_confinement_rates.php');
        $migration->up();
        $snapshot = $entry->fresh()->service_snapshot;
        $this->assertSame('650.00', $entry->fresh()->applied_price);
        $this->assertSame('pet_size', $service->fresh()->tier_basis);
        $this->assertDatabaseCount('clinic_charges', 0);
        $service->update(['price' => 900, 'pricing_method' => 'fixed']); $migration->up();
        $this->assertSame($snapshot, $entry->fresh()->service_snapshot);
    }

    public function test_missing_duplicate_and_invalid_base_configuration_blocks_admission(): void
    {
        $url = '/api/admin/clinic-cases/1/confinement';
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Sample'];
        $this->postJson($url, $payload)->assertUnprocessable();
        $base = $this->service(); $other = $this->service(['name' => 'Another base']);
        $this->postJson($url, $payload)->assertUnprocessable();
        $other->update(['is_active' => false]); $base->update(['tiers' => [['size' => 'medium', 'price' => 500]]]);
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('clinic_confinements', 0);
    }

    public function test_admission_extras_and_retry_protection_preserve_other_case_charges(): void
    {
        $this->service(['name' => 'Automatic A', 'charge_behavior' => 'admission_charge', 'pricing_method' => 'fixed', 'price' => 450, 'billing_unit' => null]);
        $this->service(['name' => 'Automatic B', 'charge_behavior' => 'admission_charge', 'pricing_method' => 'fixed', 'price' => 200]);
        $one = $this->service(['name' => 'Optional', 'charge_behavior' => 'additional_charge', 'pricing_method' => 'fixed', 'price' => 350, 'billing_unit' => null]);
        $daily = $this->service(['name' => 'Daily', 'charge_behavior' => 'additional_charge', 'pricing_method' => 'fixed', 'price' => 800]);
        $payload = $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_charges', 2);
        $url = '/api/admin/clinic-cases/1/confinement/charges';
        $add = ['clinic_service_id' => $one->id, 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($url, $add)->assertCreated()->json('data.id');
        $this->postJson($url, $add)->assertOk();
        $this->deleteJson($url.'/'.$id)->assertOk(); $this->deleteJson($url.'/'.$id)->assertOk();
        $this->postJson($url, $add)->assertOk(); // Removed requests remain tombstoned.
        $this->assertDatabaseCount('clinic_charges', 2);
        $add = ['clinic_service_id' => $daily->id, 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($url, $add)->assertCreated()->json('data.id');
        $this->postJson($url, $add)->assertOk();
        $this->postJson($url, [...$add, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($url, [...$add, 'quantity' => 3])->assertUnprocessable();
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->postJson($url.'/'.$id.'/stop')->assertOk()->assertJsonPath('data.case_service.quantity', '2.00');
        $this->postJson($url.'/'.$id.'/stop')->assertOk();
        $daily->update(['price' => 900]);
        $this->postJson($url, ['clinic_service_id' => $daily->id, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->assertDatabaseCount('clinic_charges', 5);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 800, 'quantity' => 2, 'amount' => 1600]);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 900, 'quantity' => 1, 'amount' => 900]);
        $this->assertSame(0, \App\Models\ClinicConfinementCharge::whereNotNull('started_at')->whereNull('stopped_at')->whereNull('removed_at')->count());
        $this->assertDatabaseHas('clinic_appointments', ['total_amount' => 4150, 'status' => 'for_payment']);
    }

    public function test_variable_extras_require_final_price_only_when_finalized(): void
    {
        $service = $this->service(['charge_behavior' => 'additional_charge', 'pricing_method' => 'range', 'min_price' => 500, 'max_price' => 1000]);
        $this->admit();
        $url = '/api/admin/clinic-cases/1/confinement/charges';
        $id = $this->postJson($url, ['clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson($url.'/'.$id.'/stop')->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['charge_prices' => [$id => 750]])->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 750]);
    }

    public function test_failed_discharge_rolls_back_episode_service_charge_and_total(): void
    {
        $this->admit(); DB::table('clinic_vitals')->update(['temperature_c' => null]);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertUnprocessable();
        $this->assertNull(ClinicConfinement::first()->discharged_at); $this->assertSame('ordered', ClinicCaseService::first()->status);
        $this->assertDatabaseCount('clinic_charges', 0); $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('clinic_appointments', ['status' => 'in_consultation', 'total_amount' => 0]);
    }

    public function test_database_prevents_duplicate_episodes(): void
    {
        $this->admit(); $attributes = ClinicConfinement::first()->getAttributes(); unset($attributes['id']);
        $this->expectException(QueryException::class); DB::table('clinic_confinements')->insert($attributes);
    }

    public function test_confined_list_and_count_use_active_episodes_and_discharge_retains_viewable_history(): void
    {
        $this->admit();
        foreach ([now()->toDateString(), now()->subDay()->toDateString()] as $date) {
            ClinicAppointment::find(1)->update(['appointment_date' => $date]);
            $before = ClinicAppointment::find(1)->getAttributes();
            $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 1)
                ->assertJsonCount(0, 'cases');
            $this->assertSame($before, ClinicAppointment::find(1)->getAttributes());
        }
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.status', 'in_consultation')
            ->assertJsonPath('data.0.confinement.case_service.status', 'ordered')
            ->assertJsonPath('data.0.record.primary_veterinarian_id', 1)
            ->assertJsonPath('data.0.vitals.weight_kg', '18.40');
        $this->assertDatabaseCount('clinic_appointments', 1);
        $this->assertDatabaseCount('clinic_records', 1);
        $this->assertDatabaseCount('clinic_vitals', 1);
        $this->assertDatabaseHas('clinic_case_services', ['clinic_appointment_id' => 1, 'status' => 'ordered']);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 0)->assertJsonPath('cases.0.status', 'for_payment');
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('data.case_service.status', 'performed');
    }

    public function test_a_discharged_episode_does_not_exclude_a_current_consultation(): void
    {
        $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        ClinicAppointment::find(1)->update(['status' => 'in_consultation', 'appointment_date' => now()->toDateString()]);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 0)
            ->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.id', 1)
            ->assertJsonPath('cases.0.status', 'in_consultation');
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_catalogue_options_filter_category_active_state_and_supported_units_without_name_matching(): void
    {
        $eligible = $this->service(['name' => 'Renamed hospital stay', 'charge_behavior' => 'additional_charge']);
        $this->service(['is_active' => false]); $this->service(['charge_behavior' => 'additional_charge', 'billing_unit' => 'sample']);
        $this->service(['category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id')]);
        $this->getJson('/api/admin/clinic-confinement-service-options')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $eligible->id);
        $this->signIn('admin'); $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid(), 'notes' => 'Admin monitoring'])->assertCreated();
        $this->postJson('/api/admin/clinic-appointments/1/cancel')->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
