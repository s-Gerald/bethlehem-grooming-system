<?php

namespace Tests\Feature;

use App\Models\ClinicService;
use App\Models\ClinicServiceCategory;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicServiceCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id(); $table->string('item_name'); $table->string('category');
        });
        Schema::create('clinic_charges', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->decimal('unit_price', 10, 2);
        });
        (require base_path('database/migrations/2026_10_13_000001_create_clinic_services_table.php'))->up();
        (require database_path('migrations/2026_10_14_000001_remove_clinic_service_type.php'))->up();
        Schema::table('clinic_services', fn (Blueprint $table) => $table->string('charge_behavior', 30)->nullable());
        $this->signIn('admin');
    }

    private function signIn(string $role, ?string $subrole = null, string $staffType = 'clinic'): void
    {
        $user = new User;
        $user->forceFill(['user_id' => 1, 'role' => $role, 'is_active' => true,
            'staff_type' => $role === 'staff' ? $staffType : null, 'staff_subrole' => $subrole]);
        Sanctum::actingAs($user);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => 'CBC', 'category_id' => 2, 'pricing_method' => 'fixed',
            'billing_unit' => null, 'price' => 990, 'is_active' => true, 'notes' => null,
        ], $changes);
    }

    public function test_crud_status_and_duplicate_identity(): void
    {
        $id = $this->postJson('/api/admin/clinic-services', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/admin/clinic-services', $this->payload(['type' => 'Send Out', 'price' => 500]))->assertUnprocessable();
        $this->postJson('/api/admin/clinic-services', $this->payload(['category_id' => 3]))->assertCreated();
        $this->postJson('/api/admin/clinic-services', $this->payload(['name' => '  cbc  ', 'type' => 'In   House']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/api/admin/clinic-services/'.$id, $this->payload(['price' => 1000]))->assertOk();
        $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => false])->assertOk();
        $this->getJson('/api/admin/clinic-services')->assertJsonPath('total', 1);
        $this->getJson('/api/admin/clinic-services?status=inactive')->assertJsonPath('total', 1);
        $this->postJson('/api/admin/clinic-services', $this->payload())->assertUnprocessable();
        $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => true])->assertOk();
        $this->assertDatabaseCount('clinic_services', 2);
        $this->postJson('/api/admin/clinic-services', $this->payload(['name' => 'Emergency Fee', 'type' => null]))->assertCreated();
        $this->postJson('/api/admin/clinic-services', $this->payload(['name' => 'Emergency Fee', 'type' => '  ']))->assertUnprocessable();
    }

    public function test_per_shot_catalogue_unit_and_active_imaging_filter(): void
    {
        $id = $this->postJson('/api/admin/clinic-services', $this->payload([
            'name' => 'X-ray — Additional Shot', 'category_id' => 4, 'billing_unit' => 'shot', 'price' => 350,
        ]))->assertCreated()->assertJsonPath('data.billing_unit', 'shot')->json('data.id');
        $this->postJson('/api/admin/clinic-services', $this->payload())->assertCreated();
        $this->getJson('/api/admin/clinic-service-options?scope=imaging')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
        $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => false])->assertOk();
        $this->getJson('/api/admin/clinic-service-options?scope=imaging')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_form_saves_without_status_default_to_active_and_preserve_deactivation(): void
    {
        $payload = $this->payload();
        unset($payload['is_active']);
        $id = $this->postJson('/api/admin/clinic-services', $payload)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('clinic_services', ['id' => $id, 'is_active' => true]);
        $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => false])->assertOk();
        $this->putJson('/api/admin/clinic-services/'.$id, array_replace($payload, ['price' => 1000]))
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('clinic_services', ['id' => $id, 'price' => 1000, 'is_active' => false]);
    }

    public function test_type_is_absent_from_schema_options_payloads_and_identity(): void
    {
        $this->assertFalse(Schema::hasColumn('clinic_services', 'type'));
        $options = $this->getJson('/api/admin/clinic-services/options')->assertOk()->json();
        $this->assertArrayNotHasKey('types', $options);
        $this->assertContains('Diagnostic Tests', array_column($options['categories'], 'name'));
        $this->assertNotContains('Boarding & Lodging', array_column($options['categories'], 'name'));
        $id = $this->postJson('/api/admin/clinic-services', $this->payload(['type' => 'Test Kit']))
            ->assertCreated()->json('data.id');
        $response = $this->putJson('/api/admin/clinic-services/'.$id, $this->payload(['type' => 'Send Out']))->assertOk();
        $this->assertArrayNotHasKey('type', $response->json('data'));
        $this->getJson('/api/admin/clinic-services?type=__none__')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/admin/clinic-services', $this->payload(['type' => 'In House']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_pricing_validation_and_method_changes_clear_unused_prices(): void
    {
        $this->postJson('/api/admin/clinic-services', $this->payload(['price' => -1]))->assertJsonValidationErrors('price');
        $this->postJson('/api/admin/clinic-services', $this->payload(['price' => '1.001']))->assertJsonValidationErrors('price');
        $range = $this->payload(['pricing_method' => 'range', 'min_price' => 500, 'max_price' => 1500]);
        $id = $this->postJson('/api/admin/clinic-services', $range)->assertCreated()->assertJsonPath('data.price', null)->json('data.id');
        $this->postJson('/api/admin/clinic-services', array_replace($range, ['max_price' => 499]))->assertJsonValidationErrors('max_price');
        $this->putJson('/api/admin/clinic-services/'.$id, $this->payload(['pricing_method' => 'starting_at', 'price' => 500]))
            ->assertOk()->assertJsonPath('data.min_price', null)->assertJsonPath('data.max_price', null);
        $tiered = $this->payload(['pricing_method' => 'tiered', 'tier_basis' => 'pet_size', 'tiers' => [
            ['size' => 'small', 'price' => 800], ['size' => 'extra_large', 'price' => 1050],
        ]]);
        $this->putJson('/api/admin/clinic-services/'.$id, $tiered)->assertOk()->assertJsonPath('data.price', null);
        $this->putJson('/api/admin/clinic-services/'.$id, array_replace($tiered, ['tiers' => []]))->assertJsonValidationErrors('tiers');
        $this->putJson('/api/admin/clinic-services/'.$id, array_replace($tiered, ['tiers' => [['size' => 'small', 'price' => -1]]]))->assertJsonValidationErrors('tiers.0.price');
        $this->putJson('/api/admin/clinic-services/'.$id, array_replace($tiered, ['tiers' => [['size' => 'small']]]))->assertJsonValidationErrors('tiers.0.price');
        $this->putJson('/api/admin/clinic-services/'.$id, array_replace($tiered, ['tiers' => [['size' => 'small', 'price' => 1], ['size' => 'small', 'price' => 2]]]))->assertJsonValidationErrors('tiers.0.size');
        $this->putJson('/api/admin/clinic-services/'.$id, $this->payload())->assertOk()->assertJsonPath('data.tiers', null);
    }

    public function test_inventory_products_cannot_be_added_or_reactivated_as_services(): void
    {
        DB::table('inventory_items')->insert([
            ['item_name' => 'Cefalexin', 'category' => 'medicine'],
            ['item_name' => 'Anti Rabies', 'category' => 'vaccine'],
        ]);
        foreach (['Cefalexin', 'Triamcinolone Injectable', 'Vincristine', 'Anti-Rabies Vaccine', 'Anti Rabies'] as $name) {
            $this->postJson('/api/admin/clinic-services', $this->payload(['name' => $name]))
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $id = $this->postJson('/api/admin/clinic-services', $this->payload(['name' => 'Cefalexin', 'is_active' => false]))
            ->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('clinic_services', ['id' => $id, 'is_active' => false]);
    }

    public function test_service_price_limit_applies_to_every_pricing_field_on_create_and_update(): void
    {
        $maximum = ClinicService::MAX_PRICE;
        $message = 'Price must be ₱100,000.00 or less.';
        foreach (['fixed', 'starting_at', 'range', 'tiered'] as $method) {
            $payload = $this->payload([
                'name' => 'Limit '.$method, 'pricing_method' => $method, 'price' => $maximum,
                'min_price' => $maximum, 'max_price' => $maximum, 'tier_basis' => 'pet_size',
                'tiers' => array_map(fn ($size) => ['size' => $size, 'price' => $maximum], ['small', 'medium', 'large', 'extra_large']),
            ]);
            $fields = match ($method) {
                'range' => ['min_price', 'max_price'],
                'tiered' => ['tiers.0.price', 'tiers.1.price', 'tiers.2.price', 'tiers.3.price'],
                default => ['price'],
            };
            $id = $this->postJson('/api/admin/clinic-services', $payload)->assertCreated()->json('data.id');
            $this->putJson('/api/admin/clinic-services/'.$id, $payload)->assertOk();
            foreach ($fields as $field) {
                foreach ([$maximum + 0.01, 1000000] as $overLimit) {
                    $invalid = $payload;
                    $invalid['name'] .= ' invalid';
                    data_set($invalid, $field, $overLimit);
                    $this->postJson('/api/admin/clinic-services', $invalid)
                        ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonFragment([$field => [$message]]);
                    $this->putJson('/api/admin/clinic-services/'.$id, $invalid)
                        ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonFragment([$field => [$message]]);
                }
            }
        }
    }

    public function test_filters_search_and_pagination_for_supported_categories(): void
    {
        $this->postJson('/api/admin/clinic-services', $this->payload(['category_id' => 1]))->assertCreated();
        $this->getJson('/api/admin/clinic-services/options')->assertJsonCount(5, 'categories');
        for ($i = 0; $i < 26; $i++) {
            $this->postJson('/api/admin/clinic-services', $this->payload(['name' => 'Test '.$i]))->assertCreated();
        }
        $this->getJson('/api/admin/clinic-services?category_id=1&q=CBC')->assertJsonPath('total', 1);
        $this->getJson('/api/admin/clinic-services?category_id=2&q=CBC')->assertJsonPath('total', 0);
        $this->getJson('/api/admin/clinic-services?category_id=1')->assertJsonPath('total', 1);
        foreach ([1 => 10, 2 => 10, 3 => 7] as $page => $count) {
            $this->getJson('/api/admin/clinic-services?page='.$page)
                ->assertOk()->assertJsonPath('total', 27)->assertJsonPath('per_page', 10)
                ->assertJsonPath('current_page', $page)->assertJsonPath('last_page', 3)
                ->assertJsonCount($count, 'data');
        }
    }

    public function test_only_five_categories_are_seeded_exposed_and_accepted(): void
    {
        $this->assertSame(ClinicServiceCategory::SUPPORTED_NAMES,
            ClinicServiceCategory::orderBy('id')->pluck('name')->all());
        $id = $this->postJson('/api/admin/clinic-services', $this->payload())->assertCreated()->json('data.id');
        // A stale import cannot make retired or arbitrary categories selectable again.
        foreach ([5 => 'Procedures', 7 => 'Certificates & Forms', 8 => 'Dental'] as $categoryId => $name) {
            $category = new ClinicServiceCategory;
            $category->forceFill(['id' => $categoryId, 'name' => $name])->save();
            foreach ([true, false] as $active) {
                $payload = $this->payload(['category_id' => $category->id, 'is_active' => $active]);
                $this->postJson('/api/admin/clinic-services', $payload)->assertUnprocessable()->assertJsonValidationErrors('category_id');
                $this->putJson('/api/admin/clinic-services/'.$id, $payload)->assertUnprocessable()->assertJsonValidationErrors('category_id');
            }
            $this->getJson('/api/admin/clinic-services?category_id='.$category->id)
                ->assertUnprocessable()->assertJsonValidationErrors('category_id');
            $stale = ClinicService::create([
                ...$this->payload(['category_id' => $category->id]), 'identity_key' => hash('sha256', $name),
            ]);
            $this->patchJson('/api/admin/clinic-services/'.$stale->id.'/status', ['is_active' => true])->assertNotFound();
        }
        $this->assertSame(ClinicServiceCategory::SUPPORTED_NAMES,
            array_column($this->getJson('/api/admin/clinic-services/options')->assertOk()->json('categories'), 'name'));
        $this->getJson('/api/admin/clinic-services?status=all')->assertJsonPath('total', 1);
        $this->getJson('/api/admin/clinic-service-options')->assertJsonPath('total', 1);
        foreach ([5, 7] as $removedId) {
            // Seed IDs of retired categories are also rejected when absent.
            ClinicService::where('category_id', $removedId)->delete();
            ClinicServiceCategory::whereKey($removedId)->delete();
            $this->postJson('/api/admin/clinic-services', $this->payload(['category_id' => $removedId]))
                ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
    }

    public function test_catalog_edits_preserve_charge_snapshots_and_reference(): void
    {
        $id = $this->postJson('/api/admin/clinic-services', $this->payload())->json('data.id');
        DB::table('clinic_charges')->insert(['clinic_service_id' => $id, 'description' => 'CBC · In House', 'unit_price' => 990]);
        $this->putJson('/api/admin/clinic-services/'.$id, $this->payload(['name' => 'Complete Blood Count', 'price' => 1200]))->assertOk();
        $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['clinic_service_id' => $id, 'description' => 'CBC · In House', 'unit_price' => 990]);
        $this->assertNotNull(ClinicService::find($id));
    }

    public function test_confinement_behavior_configures_base_and_enforces_complete_unique_active_base(): void
    {
        $category = ClinicServiceCategory::where('name', 'Confinement')->value('id');
        $base = $this->payload(['name' => 'Sample stay', 'category_id' => $category, 'charge_behavior' => 'base_confinement',
            'tiers' => array_map(fn ($size, $max) => ['size' => $size, 'price' => 100, 'max_weight_kg' => $max],
                ['small', 'medium', 'large', 'extra_large'], [8.5, 22, 38, null])]);
        $id = $this->postJson('/api/admin/clinic-services', $base)->assertCreated()->assertJsonPath('data.pricing_method', 'tiered')
            ->assertJsonPath('data.billing_unit', 'day')->assertJsonPath('data.tier_basis', 'weight_kg')->json('data.id');
        foreach (['', 0, -1, 'invalid', 8.5, 7] as $cutoff) {
            $invalid = $base; $invalid['tiers'][1]['max_weight_kg'] = $cutoff;
            $this->putJson('/api/admin/clinic-services/'.$id, $invalid)->assertUnprocessable()->assertJsonValidationErrors('tiers.1.max_weight_kg');
        }
        $invalid = $base; unset($invalid['tiers'][0]['max_weight_kg']);
        $this->putJson('/api/admin/clinic-services/'.$id, $invalid)->assertUnprocessable()->assertJsonValidationErrors('tiers.0.max_weight_kg');
        $invalid = $base; $invalid['tiers'][3]['max_weight_kg'] = 50;
        $this->putJson('/api/admin/clinic-services/'.$id, $invalid)->assertUnprocessable()->assertJsonValidationErrors('tiers.3.max_weight_kg');
        $this->putJson('/api/admin/clinic-services/'.$id, [...$base, 'tiers' => array_reverse($base['tiers'])])->assertOk()
            ->assertJsonPath('data.tiers.0.size', 'small')->assertJsonPath('data.tiers.0.max_weight_kg', 8.5)
            ->assertJsonPath('data.tiers.3.max_weight_kg', null);
        $this->postJson('/api/admin/clinic-services', [...$base, 'name' => 'Second base'])->assertUnprocessable()->assertJsonValidationErrors('charge_behavior');
        $this->putJson('/api/admin/clinic-services/'.$id, [...$base, 'tiers' => [['size' => 'medium', 'price' => 100]]])->assertUnprocessable();
        $second = $this->postJson('/api/admin/clinic-services', [...$base, 'name' => 'Inactive base', 'is_active' => false])->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/clinic-services/'.$second.'/status', ['is_active' => true])->assertUnprocessable();
        $this->postJson('/api/admin/clinic-services', $this->payload(['name' => 'Extra', 'category_id' => $category]))->assertUnprocessable();
        $this->postJson('/api/admin/clinic-services', $this->payload(['name' => 'Extra', 'category_id' => $category,
            'charge_behavior' => 'additional_charge', 'billing_unit' => 'day']))->assertCreated();
    }

    public function test_clinic_staff_can_browse_catalog_but_only_admin_can_manage_it(): void
    {
        $id = $this->postJson('/api/admin/clinic-services', $this->payload())->assertCreated()->json('data.id');
        foreach (['veterinarian', 'clinic_receptionist', 'grooming_receptionist'] as $subrole) {
            $this->signIn('staff', $subrole, $subrole === 'grooming_receptionist' ? 'grooming' : 'clinic');
            $this->getJson('/api/admin/clinic-services?q=CBC&category_id=2&status=all&page=1')
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
            $this->getJson('/api/admin/clinic-services/options')->assertOk()->assertJsonCount(5, 'categories');
            $this->postJson('/api/admin/clinic-services', $this->payload(['name' => 'Forbidden']))->assertForbidden();
            $this->putJson('/api/admin/clinic-services/'.$id, $this->payload(['price' => 1]))->assertForbidden();
            foreach ([false, true] as $active) {
                $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => $active])->assertForbidden();
            }
            $this->assertDatabaseHas('clinic_services', ['id' => $id, 'price' => 990, 'is_active' => true]);
            $this->assertDatabaseCount('clinic_services', 1);
        }
    }

    public function test_other_staff_and_customers_cannot_read_or_mutate_catalog(): void
    {
        $id = $this->postJson('/api/admin/clinic-services', $this->payload())->json('data.id');
        foreach ([['staff', null, 'clinic'], ['staff', 'grooming_receptionist', 'clinic'],
            ['staff', 'unknown', 'clinic'], ['customer', null, 'clinic']] as [$role, $subrole, $type]) {
            $this->signIn($role, $subrole, $type);
            $this->getJson('/api/admin/clinic-services')->assertForbidden();
            $this->getJson('/api/admin/clinic-services/options')->assertForbidden();
            $this->postJson('/api/admin/clinic-services', $this->payload())->assertForbidden();
            $this->putJson('/api/admin/clinic-services/'.$id, $this->payload())->assertForbidden();
            $this->patchJson('/api/admin/clinic-services/'.$id.'/status', ['is_active' => false])->assertForbidden();
        }
    }
}
