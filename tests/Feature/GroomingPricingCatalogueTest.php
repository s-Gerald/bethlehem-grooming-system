<?php

namespace Tests\Feature;

use App\Models\BookingPet;
use App\Models\BookingService;
use App\Models\Service;
use App\Models\User;
use App\Services\GroomingServicePriceResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingPricingCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('slug');
            $table->string('service_name');
            $table->text('description')->nullable();
            foreach (['base_price', 'price_small', 'price_medium', 'price_large', 'price_extra_large', 'price_min', 'price_max'] as $field) {
                $table->decimal($field, 8, 2)->nullable();
            }
            $table->boolean('is_starting_price')->default(false);
            $table->boolean('is_active')->default(true);
        });
        foreach (config('grooming_services.services') as $slug => $defaults) {
            DB::table('services')->insert(['slug' => $slug, 'service_name' => $slug, 'base_price' => 0]);
        }
        (require base_path('database/migrations/2026_10_06_000001_add_starting_price_sizes_to_services.php'))->up();
        (require base_path('database/migrations/2026_10_06_000002_add_range_price_maximums_to_services.php'))->up();
        (require base_path('database/migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        $this->asRole('admin');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('services');
        parent::tearDown();
    }

    private function asRole(string $role): void
    {
        Sanctum::actingAs(new User(['role' => $role]));
    }

    private function updatePrice(string $slug, array $payload)
    {
        $id = Service::where('slug', $slug)->value('service_id');

        return $this->patchJson("/api/admin/grooming/services/$id/pricing", $payload);
    }

    public function test_clinic_receptionist_reads_both_active_and_inactive_grooming_services_without_management_access(): void
    {
        Service::where('slug', 'facial_trimming')->update(['is_active' => false]);
        Sanctum::actingAs(new User(['role' => 'staff', 'staff_type' => 'clinic', 'staff_subrole' => 'clinic_receptionist']));
        $data = $this->getJson('/api/admin/grooming/services/catalogue')->assertOk()->json('data');
        $this->assertContains(false, array_column($data, 'isActive'));
        $this->assertContains(true, array_column($data, 'isActive'));
        $this->getJson('/api/admin/grooming/services')->assertForbidden();
        $id = Service::where('slug', 'facial_trimming')->value('service_id');
        $this->postJson('/api/admin/grooming/services', [])->assertForbidden();
        $this->putJson("/api/admin/grooming/services/{$id}", [])->assertForbidden();
        $this->patchJson("/api/admin/grooming/services/{$id}/status", ['isActive' => true])->assertForbidden();
        $this->updatePrice('facial_trimming', ['pricing_type' => 'fixed', 'amount' => '999'])->assertForbidden();
        $this->assertDatabaseHas('services', ['service_id' => $id, 'is_active' => false, 'base_price' => 150]);
        foreach (['veterinarian', 'grooming_receptionist'] as $subrole) {
            Sanctum::actingAs(new User(['role' => 'staff', 'staff_type' => $subrole === 'veterinarian' ? 'clinic' : 'grooming', 'staff_subrole' => $subrole]));
            $this->getJson('/api/admin/grooming/services/catalogue')->assertForbidden();
        }
        $this->asRole('admin');
        $this->getJson('/api/admin/grooming/services/catalogue')->assertOk();
    }

    public function test_staff_and_customer_cannot_update_prices(): void
    {
        foreach (['staff', 'customer'] as $role) {
            $this->asRole($role);
            $this->updatePrice('facial_trimming', ['pricing_type' => 'fixed', 'amount' => '200'])->assertForbidden();
            $this->updatePrice('cat_full_grooming', ['sizes' => [
                'small' => ['pricing_type' => 'range', 'amount' => '500', 'maximum' => '550'],
                'medium' => ['pricing_type' => 'fixed', 'amount' => '600'],
            ]])->assertForbidden();
            $this->getJson('/api/grooming/services')->assertOk();
        }
        $this->assertEquals(150, Service::where('slug', 'facial_trimming')->value('base_price'));
        $this->assertNull(Service::where('slug', 'cat_full_grooming')->first()->range_price_maximums);
    }

    public function test_unauthenticated_catalogue_and_updates_require_a_token(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/grooming/services')->assertUnauthorized();
        $this->updatePrice('facial_trimming', ['pricing_type' => 'fixed', 'amount' => '200'])->assertUnauthorized();
    }

    public function test_ala_carte_modes_clear_obsolete_values_and_are_live(): void
    {
        foreach ([['range', '60.50', '120.75'], ['starting_at', '175', null], ['fixed', '180.25', null]] as [$type, $amount, $max]) {
            $response = $this->updatePrice('nail_clipping', ['pricing_type' => $type, 'amount' => $amount, 'maximum' => $max])->assertOk();
            $response->assertJsonPath('data.priceOptions.0.pricingType', $type);
            $service = Service::where('slug', 'nail_clipping')->first();
            $this->assertEquals($type === 'fixed' ? null : $amount, $service->price_min);
            $this->assertEquals($max, $service->price_max);
            $this->assertSame($type === 'starting_at', $service->is_starting_price);
            $live = collect($this->getJson('/api/grooming/services')->assertOk()->json('data'))->firstWhere('id', 'nail_clipping');
            $this->assertEquals((float) $amount, $live['priceOptions'][0]['minAmount']);
            $this->assertSame($type, $live['priceOptions'][0]['pricingType']);
        }
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_prices_are_rejected_atomically(array $payload, string $field): void
    {
        $before = Service::where('slug', 'nail_clipping')->first()->getAttributes();
        $this->updatePrice('nail_clipping', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, Service::where('slug', 'nail_clipping')->first()->getAttributes());
    }

    public static function invalidPrices(): array
    {
        $cases = [];
        foreach (['', '0', '-1', '1e3', '50.001', '₱50', '50,000', 'NaN', '9999999'] as $value) {
            $cases[] = [['pricing_type' => 'fixed', 'amount' => $value], 'amount'];
        }
        foreach ([null, '50', '49', '50.001'] as $maximum) {
            $cases[] = [['pricing_type' => 'range', 'amount' => '50', 'maximum' => $maximum], 'maximum'];
        }
        $cases[] = [['pricing_type' => 'other', 'amount' => '50'], 'pricing_type'];

        return $cases;
    }

    #[DataProvider('groomingKindsAndTypes')]
    public function test_grooming_business_limits_cover_every_type_and_range_bound(string $kind, string $type): void
    {
        $slug = $kind === 'package' ? 'cat_full_grooming' : 'nail_clipping';
        $limits = config("grooming_services.price_limits.$kind");
        $minimum = $limits['minimum'];
        $maximum = $limits['maximum'];
        $payload = function ($amount, $upper = null) use ($kind, $type) {
            $row = ['pricing_type' => $type, 'amount' => $amount];
            if ($type === 'range') $row['maximum'] = $upper;

            return $kind === 'package' ? ['sizes' => ['small' => $row, 'medium' => $row]] : $row;
        };
        foreach ($type === 'range'
            ? [[$minimum, $minimum + 0.01], [$maximum - 0.01, $maximum]]
            : [[$minimum, null], [$maximum, null]] as [$amount, $upper]) {
            $this->updatePrice($slug, $payload((string) $amount, $upper === null ? null : (string) $upper))->assertOk();
        }
        $before = Service::where('slug', $slug)->first()->getAttributes();
        $invalidAmounts = [null, '', '0', '0.99', '-1', 'NaN', 'Infinity', '1e3', '1E3', '+500', '1.001', '₱500', '1,000', "500\n", (string) ($maximum + 0.01)];
        $field = $kind === 'package' ? 'sizes.small.amount' : 'amount';
        foreach ($invalidAmounts as $invalid) {
            $response = $this->updatePrice($slug, $payload($invalid, (string) $maximum))->assertUnprocessable();
            $this->assertSame(['Enter a reasonable price amount.'], $response->json('errors')[$field]);
            $this->assertSame($before, Service::where('slug', $slug)->first()->getAttributes());
        }
        if ($type === 'range') {
            $field = $kind === 'package' ? 'sizes.small.maximum' : 'maximum';
            foreach ($invalidAmounts as $invalid) {
                $response = $this->updatePrice($slug, $payload((string) $minimum, $invalid))->assertUnprocessable();
                $this->assertSame(['Enter a reasonable price amount.'], $response->json('errors')[$field]);
                $this->assertSame($before, Service::where('slug', $slug)->first()->getAttributes());
            }
            foreach (['500', '499'] as $upper) {
                $response = $this->updatePrice($slug, $payload('500', $upper))->assertUnprocessable();
                $this->assertSame(['Maximum price must be greater than minimum price.'], $response->json('errors')[$field]);
                $this->assertSame($before, Service::where('slug', $slug)->first()->getAttributes());
            }
        }
    }

    public static function groomingKindsAndTypes(): array
    {
        return [['package', 'fixed'], ['package', 'starting_at'], ['package', 'range'],
            ['ala_carte', 'fixed'], ['ala_carte', 'starting_at'], ['ala_carte', 'range']];
    }

    public function test_catalogue_exposes_the_same_configured_limits_used_for_updates(): void
    {
        $this->getJson('/api/grooming/services')->assertOk()
            ->assertJsonPath('priceLimits.package.minimum', 1)
            ->assertJsonPath('priceLimits.package.maximum', 20000)
            ->assertJsonPath('priceLimits.ala_carte.minimum', 1)
            ->assertJsonPath('priceLimits.ala_carte.maximum', 5000);
        config(['grooming_services.price_limits.ala_carte.maximum' => 300]);
        $this->getJson('/api/grooming/services')->assertOk()->assertJsonPath('priceLimits.ala_carte.maximum', 300);
        $this->updatePrice('nail_clipping', ['pricing_type' => 'fixed', 'amount' => '300'])->assertOk();
        $this->updatePrice('nail_clipping', ['pricing_type' => 'fixed', 'amount' => '300.01'])
            ->assertUnprocessable()->assertJsonPath('errors.amount.0', 'Enter a reasonable price amount.');
    }

    public function test_catalogue_limits_do_not_replace_starting_price_payment_safety_rules(): void
    {
        foreach (['package' => ['cat_full_grooming', 500], 'ala_carte' => ['ear_cleaning', 200]] as $kind => [$slug, $allowance]) {
            $amount = config("grooming_services.price_limits.$kind.maximum");
            $row = ['pricing_type' => 'starting_at', 'amount' => (string) $amount];
            $payload = $kind === 'package' ? ['sizes' => ['small' => $row, 'medium' => $row]] : $row;
            $this->updatePrice($slug, $payload)->assertOk();
            $service = Service::where('slug', $slug)->first();
            $line = new BookingService(['price_at_booking' => $amount, 'price_min_at_booking' => $amount]);
            $line->setRelation('service', $service);
            $line->setRelation('bookingPet', new BookingPet(['registered_size' => 'small']));
            $rules = app(GroomingServicePriceResolver::class)->paymentPriceRules($line, 'small');
            $this->assertEquals($amount + $allowance, $rules['safety_max']);
            $this->assertNull($rules['max']);
            $this->assertSame('plus', $rules['pricing_type']);
        }
    }

    public function test_package_modes_supported_sizes_and_order(): void
    {
        $payload = ['sizes' => ['small' => ['pricing_type' => 'starting_at', 'amount' => '600'],
            'medium' => ['pricing_type' => 'fixed', 'amount' => '700'],
            'large' => ['pricing_type' => 'starting_at', 'amount' => '900'],
            'extra_large' => ['pricing_type' => 'starting_at', 'amount' => '1100']]];
        $this->updatePrice('regular_dog_grooming', $payload)->assertOk()->assertJsonPath('data.priceOptions.0.pricingType', 'starting_at');
        $service = Service::where('slug', 'regular_dog_grooming')->first();
        $this->assertSame(['small', 'large', 'extra_large'], $service->starting_price_sizes);
        $before = $service->getAttributes();
        $payload['sizes']['medium']['amount'] = '500';
        $this->updatePrice($service->slug, $payload)->assertUnprocessable()->assertJsonValidationErrors('sizes.medium.amount');
        $payload['sizes']['medium'] = ['pricing_type' => 'range', 'amount' => '700'];
        $this->updatePrice($service->slug, $payload)->assertUnprocessable()->assertJsonValidationErrors('sizes.medium.maximum');
        $payload['sizes']['medium']['pricing_type'] = 'other';
        $this->updatePrice($service->slug, $payload)->assertUnprocessable()->assertJsonValidationErrors('sizes.medium.pricing_type');
        $this->assertSame($before, $service->fresh()->getAttributes());
        $this->updatePrice('cat_full_grooming', $payload)->assertUnprocessable()->assertJsonValidationErrors('sizes');
        unset($payload['sizes']['large'], $payload['sizes']['extra_large']);
        $payload['sizes']['medium']['pricing_type'] = 'fixed';
        $this->updatePrice('cat_full_grooming', $payload)->assertOk()->assertJsonCount(2, 'data.priceOptions');
    }

    public function test_backfill_preserves_defaults_and_catalogue_is_not_cached(): void
    {
        $response = $this->getJson('/api/grooming/services')->assertOk()->assertJsonCount(10, 'data');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $services = collect($response->json('data'))->keyBy('id');
        $this->assertEquals(1050, $services['regular_dog_grooming']['priceOptions'][3]['minAmount']);
        $this->assertSame('starting_at', $services['regular_dog_grooming']['priceOptions'][3]['pricingType']);
        $this->assertSame('range', $services['nail_clipping']['priceOptions'][0]['pricingType']);
        $this->assertSame('starting_at', $services['ear_cleaning']['priceOptions'][0]['pricingType']);
        $this->assertSame('fixed', $services['facial_trimming']['priceOptions'][0]['pricingType']);
    }

    public function test_official_defaults_are_independent_of_current_catalogue_prices(): void
    {
        $original = collect($this->getJson('/api/grooming/services')->assertOk()->json('data'))->keyBy('id');
        foreach ($original as $service) {
            $this->assertSame($service['priceOptions'], $service['defaultPriceOptions']);
        }
        $this->assertEquals(550, $original['bath_and_go']['defaultPriceOptions'][1]['minAmount']);
        $this->assertCount(2, $original['cat_full_grooming']['defaultPriceOptions']);

        $this->updatePrice('nail_clipping', ['pricing_type' => 'fixed', 'amount' => '175'])->assertOk()
            ->assertJsonPath('data.defaultPriceOptions.0.pricingType', 'range')
            ->assertJsonPath('data.defaultPriceOptions.0.minAmount', 50)
            ->assertJsonPath('data.defaultPriceOptions.0.maxAmount', 100);
        $bath = $original['bath_and_go'];
        $payload = ['sizes' => []];
        foreach ($bath['priceOptions'] as $option) {
            $payload['sizes'][$option['sizeKey']] = ['pricing_type' => 'range',
                'amount' => $option['minAmount'] + 50, 'maximum' => $option['minAmount'] + 100];
        }
        $this->updatePrice('bath_and_go', $payload)->assertOk()
            ->assertJsonPath('data.priceOptions.1.minAmount', 600)
            ->assertJsonPath('data.defaultPriceOptions.1.minAmount', 550)
            ->assertJsonPath('data.defaultPriceOptions.1.pricingType', 'fixed')
            ->assertJsonPath('data.defaultPriceOptions.2.pricingType', 'starting_at');
        $latest = collect($this->getJson('/api/grooming/services')->assertOk()->json('data'))->keyBy('id');
        foreach ($latest as $id => $service) {
            $this->assertSame($original[$id]['defaultPriceOptions'], $service['defaultPriceOptions']);
        }
    }

    public function test_package_ranges_are_live_and_clear_when_changing_modes(): void
    {
        $payload = ['sizes' => [
            'small' => ['pricing_type' => 'range', 'amount' => '500.50', 'maximum' => '580.75'],
            'medium' => ['pricing_type' => 'range', 'amount' => '650', 'maximum' => '800'],
        ]];
        $this->updatePrice('cat_full_grooming', $payload)->assertOk()
            ->assertJsonPath('data.priceOptions.0.pricingType', 'range')
            ->assertJsonPath('data.priceOptions.0.minAmount', 500.5)
            ->assertJsonPath('data.priceOptions.0.maxAmount', 580.75);
        $service = Service::where('slug', 'cat_full_grooming')->first();
        $this->assertEquals(['small' => '580.75', 'medium' => '800'], $service->range_price_maximums);
        $live = collect($this->getJson('/api/grooming/services')->assertOk()->json('data'))->firstWhere('id', $service->slug);
        $this->assertSame('range', $live['priceOptions'][1]['pricingType']);
        $this->assertEquals(800, $live['priceOptions'][1]['maxAmount']);
        $resolver = app(GroomingServicePriceResolver::class);
        $line = new BookingService(['price_at_booking' => 0, 'price_min_at_booking' => 500.50, 'price_max_at_booking' => 580.75]);
        $line->setRelation('service', $service);
        $line->setRelation('bookingPet', new BookingPet(['registered_size' => 'small']));
        $corrected = $resolver->paymentPriceRules($line, 'medium');
        $this->assertEquals(650, $corrected['min']);
        $this->assertEquals(800, $corrected['max']);
        $this->assertSame('range', $corrected['pricing_type']);
        $this->assertNull($corrected['safety_max']);
        $this->assertNull($corrected['review_threshold']);

        $payload['sizes']['small']['pricing_type'] = 'fixed';
        $payload['sizes']['medium']['pricing_type'] = 'starting_at';
        // Even obsolete maxima supplied by a stale browser cannot affect the new modes.
        $this->updatePrice($service->slug, $payload)->assertOk()
            ->assertJsonPath('data.priceOptions.0.pricingType', 'fixed')
            ->assertJsonPath('data.priceOptions.1.pricingType', 'starting_at');
        $this->assertSame([], $service->fresh()->range_price_maximums);
        $this->assertSame(['medium'], $service->fresh()->starting_price_sizes);
        $line->setRelation('service', $service->fresh());
        $historical = $resolver->paymentPriceRules($line, 'small');
        $this->assertSame('range', $historical['pricing_type']);
        $this->assertEquals(500.50, $historical['min']);
        $this->assertEquals(580.75, $historical['max']);
    }

    #[DataProvider('invalidPackageRanges')]
    public function test_invalid_package_ranges_are_rejected_atomically(mixed $maximum): void
    {
        $service = Service::where('slug', 'cat_full_grooming')->first();
        $before = $service->getAttributes();
        $payload = ['sizes' => [
            'small' => ['pricing_type' => 'range', 'amount' => '500', 'maximum' => $maximum],
            'medium' => ['pricing_type' => 'starting_at', 'amount' => '700'],
        ]];
        $this->updatePrice($service->slug, $payload)->assertUnprocessable()->assertJsonValidationErrors('sizes.small.maximum');
        $this->assertSame($before, $service->fresh()->getAttributes());
        $payload['sizes']['small'] = ['pricing_type' => 'range', 'amount' => '500', 'maximum' => '550'];
        $payload['sizes']['medium'] = ['pricing_type' => 'range', 'amount' => '490', 'maximum' => '800'];
        $this->updatePrice($service->slug, $payload)->assertUnprocessable()->assertJsonValidationErrors('sizes.medium.amount');
        $this->assertSame($before, $service->fresh()->getAttributes());
    }

    public static function invalidPackageRanges(): array
    {
        return array_map(fn ($maximum) => [$maximum], [null, '', '0', '-1', '500', '499', '600.001', '1e3', '₱600', '600,000', '9999999']);
    }

    public function test_range_migration_is_additive_and_reversible(): void
    {
        $migration = require base_path('database/migrations/2026_10_06_000002_add_range_price_maximums_to_services.php');
        $before = $this->getJson('/api/grooming/services')->assertOk()->json('data');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('services', 'range_price_maximums'));
        $migration->up();
        $this->assertSame($before, $this->getJson('/api/grooming/services')->assertOk()->json('data'));
    }

    public function test_reseeding_preserves_admin_prices_and_backfill_preserves_existing_amounts(): void
    {
        // Match the unique catalogue slug constraint used by the application's schema.
        Schema::table('services', fn (Blueprint $table) => $table->unique('slug'));
        $this->updatePrice('ear_cleaning', ['pricing_type' => 'fixed', 'amount' => '210'])->assertOk();
        $this->seed(\Database\Seeders\ServiceSeeder::class);
        $service = Service::where('slug', 'ear_cleaning')->first();
        $this->assertEquals(210, $service->base_price);
        $this->assertFalse($service->is_starting_price);
        $migration = require base_path('database/migrations/2026_10_06_000001_add_starting_price_sizes_to_services.php');
        $migration->down();
        DB::table('services')->where('slug', 'regular_dog_grooming')->update(['price_small' => 625, 'price_medium' => 725]);
        $migration->up();
        $package = Service::where('slug', 'regular_dog_grooming')->first();
        $this->assertEquals(625, $package->price_small);
        $this->assertEquals(725, $package->price_medium);
        $this->assertEquals(1050, $package->price_extra_large);
    }

    public function test_legacy_starting_snapshot_keeps_its_rule_after_current_mode_changes(): void
    {
        $service = Service::where('slug', 'ear_cleaning')->first();
        $this->updatePrice('ear_cleaning', ['pricing_type' => 'fixed', 'amount' => '400'])->assertOk();
        $line = new BookingService(['price_at_booking' => 150]);
        $line->setRelation('service', $service->fresh());
        $line->setRelation('bookingPet', new BookingPet(['registered_size' => 'small']));
        $rules = app(GroomingServicePriceResolver::class)->paymentPriceRules($line, 'small');
        $this->assertSame('plus', $rules['pricing_type']);
        $this->assertEquals(150, $rules['min']);
        $this->assertEquals(350, $rules['safety_max']);
        $line->price_min_at_booking = 400;
        $line->price_max_at_booking = 400;
        $this->assertSame('fixed', app(GroomingServicePriceResolver::class)->paymentPriceRules($line, 'small')['pricing_type']);
    }

    public function test_snapshot_rules_survive_changes_and_size_correction_uses_current_prices(): void
    {
        $resolver = app(GroomingServicePriceResolver::class);
        $service = Service::where('slug', 'regular_dog_grooming')->first();
        $line = new BookingService(['price_at_booking' => 850, 'price_min_at_booking' => 850]);
        $line->setRelation('service', $service);
        $line->setRelation('bookingPet', new BookingPet(['registered_size' => 'large']));
        $payload = ['sizes' => []];
        foreach (['small' => 600, 'medium' => 700, 'large' => 950, 'extra_large' => 1150] as $size => $amount) {
            $payload['sizes'][$size] = ['pricing_type' => 'starting_at', 'amount' => (string) $amount];
        }
        $this->updatePrice($service->slug, $payload)->assertOk();
        $line->setRelation('service', $service->fresh());
        $historical = $resolver->paymentPriceRules($line, 'large');
        $this->assertEquals(850, $historical['min']);
        $this->assertEquals(1350, $historical['safety_max']);
        $this->assertEquals(1150, $historical['review_threshold']);
        $corrected = $resolver->paymentPriceRules($line, 'small');
        $this->assertEquals(600, $corrected['min']);
        $this->assertEquals(1100, $corrected['safety_max']);
        $this->assertEquals(850, $line->price_at_booking);
        $this->assertEquals(850, $line->price_min_at_booking);
        $this->assertNull($line->price_max_at_booking);
        $this->updatePrice('facial_trimming', ['pricing_type' => 'starting_at', 'amount' => '250'])->assertOk();
        $variable = Service::where('slug', 'facial_trimming')->first();
        $line->setRelation('service', $variable);
        $line->price_min_at_booking = 250;
        $rules = $resolver->paymentPriceRules($line, 'small');
        $this->assertEquals(450, $rules['safety_max']);
        $this->assertNull($rules['max']);
        $line->price_min_at_booking = null;
        $line->price_at_booking = 150;
        $this->assertSame('fixed', $resolver->paymentPriceRules($line, 'small')['pricing_type']);
        $this->assertEquals(150, $resolver->paymentPriceRules($line, 'small')['max']);
    }
}
