<?php

namespace Tests\Feature;

use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\Service;
use App\Models\User;
use App\Services\GroomingServicePriceResolver;
use App\Services\GroomingServiceSelection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroomingManagedCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('slug')->unique();
            $table->string('service_name');
            $table->text('description')->nullable();
            foreach (['base_price', 'price_small', 'price_medium', 'price_large', 'price_extra_large', 'price_min', 'price_max'] as $field) {
                $table->decimal($field, 8, 2)->nullable();
            }
            $table->boolean('is_starting_price')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('starting_price_sizes')->nullable();
            $table->json('range_price_maximums')->nullable();
        });
        $this->seed(\Database\Seeders\ServiceSeeder::class);
        (require base_path('database/migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        Sanctum::actingAs(new User(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('services');
        parent::tearDown();
    }

    private function packagePayload(int $included): array
    {
        return ['name' => 'Coat Care Grooming', 'kind' => 'package', 'petType' => 'dog',
            'descriptionItems' => ['Gentle bath and coat conditioning', 'Careful brushing'],
            'includedServiceIds' => [$included], 'isActive' => true, 'displayOrder' => 2,
            'sizes' => ['small' => ['pricing_type' => 'fixed', 'amount' => '600'],
                'medium' => ['pricing_type' => 'fixed', 'amount' => '700'],
                'large' => ['pricing_type' => 'starting_at', 'amount' => '900'],
                'extra_large' => ['pricing_type' => 'starting_at', 'amount' => '1100']]];
    }

    public function test_facial_inclusion_update_is_additive_idempotent_and_preserves_catalog_settings(): void
    {
        $facial = Service::where('slug', 'facial_trimming')->value('service_id');
        $partial = Service::where('slug', 'partial_grooming')->firstOrFail();
        $partial->update(['included_ala_carte_ids' => [999], 'is_active' => false, 'price_small' => 432]);
        $bath = Service::where('slug', 'bath_and_go')->firstOrFail()->getAttributes();
        $cat = Service::where('slug', 'cat_full_grooming')->firstOrFail()->getAttributes();
        $migration = require database_path('migrations/2026_10_26_000002_include_facial_trimming_in_dog_packages.php');
        $migration->up();
        $migration->up();
        $this->assertSame([999, $facial], $partial->fresh()->included_ala_carte_ids);
        $this->assertFalse($partial->fresh()->is_active);
        $this->assertEquals(432, $partial->fresh()->price_small);
        $this->assertSame($bath, Service::where('slug', 'bath_and_go')->firstOrFail()->getAttributes());
        $this->assertSame($cat, Service::where('slug', 'cat_full_grooming')->firstOrFail()->getAttributes());
        foreach (['regular_dog_grooming', 'deluxe_dog_grooming'] as $slug) {
            $this->assertContains($facial, Service::where('slug', $slug)->firstOrFail()->included_ala_carte_ids);
        }
    }

    public function test_managed_catalog_creates_and_updates_complete_booking_metadata(): void
    {
        $ear = Service::where('slug', 'ear_cleaning')->first();
        $payload = $this->packagePayload($ear->service_id);
        $created = $this->postJson('/api/admin/grooming/services', $payload)->assertCreated()
            ->assertJsonPath('data.name', 'Coat Care Grooming')->assertJsonPath('data.descriptionItems.1', 'Careful brushing')
            ->assertJsonPath('data.includedAlaCarteServiceIds', ['ear_cleaning'])
            ->assertJsonPath('data.priceOptions.2.pricingType', 'starting_at')->json('data');
        $service = Service::findOrFail($created['serviceId']);
        $this->assertSame('900.00', app(GroomingServicePriceResolver::class)->servicePrice($service, 'large'));
        $payload['name'] = 'Coat and Skin Care';
        $payload['descriptionItems'] = ['Nail Clipping is available separately'];
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertOk()
            ->assertJsonPath('data.id', $created['id'])->assertJsonPath('data.includedAlaCarteServiceIds', ['ear_cleaning']);
        $catalog = collect($this->getJson('/api/grooming/services')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('Coat and Skin Care', $catalog[$created['id']]['name']);
        $this->assertSame(['Nail Clipping is available separately'], $catalog[$created['id']]['descriptionItems']);

    }

    public function test_managed_catalog_deactivation_preserves_service_and_historical_prices(): void
    {
        $service = Service::where('slug', 'ear_cleaning')->first();
        $line = new BookingService(['price_at_booking' => 150, 'price_min_at_booking' => 150]);
        $line->setRelation('bookingPet', new BookingPet(['registered_size' => 'small']));
        $this->patchJson('/api/admin/grooming/services/'.$service->service_id.'/status', ['isActive' => false])->assertOk();
        $catalog = $this->getJson('/api/grooming/services')->assertOk()->json('data');
        $this->assertNotContains('ear_cleaning', array_column($catalog, 'id'));
        $this->getJson('/api/admin/grooming/services')->assertOk()->assertJsonCount(10, 'data');
        $line->setRelation('service', $service->fresh());
        $rules = app(GroomingServicePriceResolver::class)->paymentPriceRules($line, 'small');
        $this->assertEquals(150, $rules['min']);
        $this->assertSame('plus', $rules['pricing_type']);
        $this->patchJson('/api/admin/grooming/services/'.$service->service_id.'/status', ['isActive' => true])->assertOk();
        $this->getJson('/api/grooming/services')->assertOk()->assertJsonCount(10, 'data');
    }

    public function test_managed_catalog_rejects_invalid_inclusions_and_prices_without_partial_updates(): void
    {
        $service = Service::where('slug', 'partial_grooming')->first();
        $payload = $this->packagePayload($service->service_id);
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('includedServiceIds.0');
        $payload['includedServiceIds'] = [];
        $payload['sizes']['medium']['amount'] = '0';
        $before = $service->getAttributes();
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('sizes.medium.amount');
        $this->assertSame($before, $service->fresh()->getAttributes());
    }

    public function test_managed_catalog_new_ala_carte_is_available_for_pricing_and_selection(): void
    {
        $created = $this->postJson('/api/admin/grooming/services', ['name' => 'Paw Balm Care', 'kind' => 'ala_carte',
            'amount' => '180', 'pricing_type' => 'fixed', 'isActive' => true, 'displayOrder' => 9])->assertCreated()->json('data');
        $this->assertSame(['cat', 'dog'], $created['petTypes']);
        $this->assertSame('180.00', app(GroomingServicePriceResolver::class)->servicePrice(Service::find($created['serviceId']), 'small'));

    }

    public function test_managed_catalog_only_admin_can_manage_services(): void
    {
        foreach (['staff', 'customer'] as $role) {
            Sanctum::actingAs(new User(['role' => $role]));
            $this->postJson('/api/admin/grooming/services', [])->assertForbidden();
            $this->getJson('/api/admin/grooming/services')->assertForbidden();
            $this->putJson('/api/admin/grooming/services/1', [])->assertForbidden();
            $this->patchJson('/api/admin/grooming/services/1/status', ['isActive' => false])->assertForbidden();
        }
    }

    public function test_inactive_services_remain_readable_for_staff_billing_only(): void
    {
        Service::where('slug', 'regular_dog_grooming')->update(['is_active' => false]);
        foreach (['admin', 'staff', 'customer'] as $role) {
            Sanctum::actingAs(new User(['role' => $role]));
            $response = $this->getJson('/api/grooming/services?include_inactive=1')->assertOk();
            $this->assertSame($role !== 'customer', in_array('regular_dog_grooming', array_column($response->json('data'), 'id')));
            $this->getJson('/api/grooming/services')->assertOk()->assertJsonCount(9, 'data');
        }
    }

    public function test_newly_enabled_package_sizes_are_available_to_booking_selection(): void
    {
        $service = Service::where('slug', 'cat_full_grooming')->firstOrFail();
        $payload = $this->packagePayload(Service::where('slug', 'ear_cleaning')->value('service_id'));
        $payload['petType'] = 'cat';
        $this->putJson('/api/admin/grooming/services/'.$service->service_id, $payload)->assertOk()
            ->assertJsonCount(4, 'data.priceOptions');

        app(GroomingServiceSelection::class)->validate(['size' => 'large'], $service->slug, [], 'cat', 'pet');
        $this->assertSame('900.00', app(GroomingServicePriceResolver::class)->servicePrice($service->fresh(), 'large'));
    }

    public function test_catalog_order_uses_lowest_then_highest_price_then_name_for_every_consumer(): void
    {
        Service::query()->delete();
        foreach ([
            ['A High', 400, 700, 900, false], ['B High', 400, 800, 850, false],
            ['Z Low', 300, null, null, false], ['Basic Groom', 400, 700, null, false],
            ['Apple Groom', 400, 700, null, false], ['C Starting', 400, 700, null, true],
        ] as $index => [$name, $medium, $large, $maximum, $starting]) {
            Service::create(['slug' => 'ordered_package_'.$index, 'service_name' => $name, 'kind' => 'package',
                'pet_type' => 'dog', 'description_items' => ['Bath and coat care'],
                'base_price' => $medium, 'price_medium' => $medium, 'price_large' => $large,
                'starting_price_sizes' => $starting ? ['medium'] : [],
                'range_price_maximums' => $maximum ? ['medium' => $maximum] : [],
                'is_active' => true, 'display_order' => $index]);
        }
        foreach ([['B Lower', 300, null, false], ['A Higher', 400, null, false],
            ['B Range', 400, 850, false], ['A Range', 400, 700, false],
            ['Basic Care', 400, null, false], ['Apple Care', 400, null, false], ['C Starting', 400, null, true],
        ] as $index => [$name, $amount, $maximum, $starting]) {
            Service::create(['slug' => 'ordered_ala_'.$index, 'service_name' => $name, 'kind' => 'ala_carte',
                'pet_type' => 'all', 'base_price' => $amount, 'price_min' => $maximum || $starting ? $amount : null,
                'price_max' => $maximum, 'is_starting_price' => $starting, 'starting_price_sizes' => [],
                'is_active' => true, 'display_order' => $index]);
        }
        foreach (['admin', 'staff', 'customer'] as $role) {
            Sanctum::actingAs(new User(['role' => $role]));
            $endpoints = $role === 'admin' ? ['/api/grooming/services', '/api/admin/grooming/services'] : ['/api/grooming/services'];
            foreach ($endpoints as $endpoint) {
                $rows = collect($this->getJson($endpoint)->assertOk()->json('data'));
                $this->assertSame(['Z Low', 'Apple Groom', 'Basic Groom', 'C Starting', 'B High', 'A High'],
                    $rows->where('kind', 'package')->pluck('name')->all());
                $this->assertSame(['B Lower', 'A Higher', 'Apple Care', 'Basic Care', 'C Starting', 'A Range', 'B Range'],
                    $rows->where('kind', 'ala_carte')->pluck('name')->all());
            }
        }
    }

    public function test_services_can_be_added_and_edited_without_manual_display_order(): void
    {
        $created = $this->postJson('/api/admin/grooming/services', ['name' => 'Paw Care', 'kind' => 'ala_carte',
            'pricing_type' => 'fixed', 'amount' => '180', 'isActive' => true])->assertCreated()->json('data');
        $this->putJson('/api/admin/grooming/services/'.$created['serviceId'], ['name' => 'Paw Care', 'kind' => 'ala_carte',
            'pricing_type' => 'fixed', 'amount' => '200', 'isActive' => true])->assertOk()->assertJsonPath('data.priceOptions.0.minAmount', 200);
    }
}
