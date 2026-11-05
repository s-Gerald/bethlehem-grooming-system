<?php

namespace Tests\Feature;

use App\Models\BookingPet;
use App\Models\Service;
use App\Models\User;
use App\Services\GroomingDuration;
use App\Services\GroomingServiceSelection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroomingTimeCmsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('services', function (Blueprint $t) {
            $t->increments('service_id'); $t->string('slug')->unique(); $t->string('service_name');
            $t->text('description')->nullable();
            foreach (['base_price','price_small','price_medium','price_large','price_extra_large','price_min','price_max'] as $field) $t->decimal($field, 8, 2)->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_starting_price')->default(false);
            $t->json('starting_price_sizes')->nullable(); $t->json('range_price_maximums')->nullable();
        });
        $this->seed(\Database\Seeders\ServiceSeeder::class);
        (require database_path('migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        Schema::create('booking_pets', function (Blueprint $t) {
            $t->increments('booking_pet_id'); $t->string('grooming_preference')->nullable();
        });
        DB::table('booking_pets')->insert(['grooming_preference' => 'summer_cut']);
        (require database_path('migrations/2026_11_05_000001_manage_grooming_times_and_haircuts.php'))->up();
        Sanctum::actingAs(new User(['role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('booking_pets'); Schema::dropIfExists('services');
        parent::tearDown();
    }

    private function service(string $slug = 'regular_dog_grooming'): Service { return Service::where('slug', $slug)->firstOrFail(); }
    private function time(string $hours, string $minutes, ?array $maximum = null): array
    {
        return ['enabled' => true, 'mode' => $maximum ? 'range' : 'fixed', 'min' => compact('hours', 'minutes')]
            + ($maximum ? ['max' => ['hours' => $maximum[0], 'minutes' => $maximum[1]]] : []);
    }
    private function payload(Service $service, array $config): array
    {
        return ['name' => $service->service_name, 'kind' => $service->kind, 'petType' => $service->pet_type,
            'descriptionItems' => $service->description_items, 'includedServiceIds' => $service->included_ala_carte_ids,
            'isActive' => true, 'sizes' => collect($service->pricingSizes())->mapWithKeys(fn ($s) => [$s => ['pricing_type' => 'fixed', 'amount' => $service->{'price_'.$s}]])->all(),
            'timeConfiguration' => $config];
    }
    private function saveConfig(Service $service, array $config)
    { return $this->putJson('/api/admin/grooming/services/'.$service->service_id, $this->payload($service, $config)); }

    public function test_migration_preserves_complete_estimates_and_historical_names(): void
    {
        $regular = $this->service();
        $this->assertSame([75, 90], $regular->legacy_complete_estimates['semi_kalbo']['small'] ?? null);
        $this->assertEmpty($regular->time_estimates);
        $this->assertSame('Summer Cut', BookingPet::first()->grooming_preference_label);
        $estimate = app(GroomingDuration::class)->estimate(['size'=>'small','grooming_preference'=>'semi_kalbo',
            'services'=>['package'=>$regular->slug]], Service::all());
        $this->assertSame(['min_minutes'=>75,'max_minutes'=>90], $estimate);
    }

    public function test_component_times_override_legacy_and_unset_stays_unset(): void
    {
        $service = $this->service();
        $config = ['estimates'=>['small'=>$this->time('0','10',['0','20'])], 'haircuts'=>[
            ['id'=>'kalbo','name'=>'Kalbo','isActive'=>true,'estimates'=>['small'=>$this->time('0','30',['0','45'])]],
        ]];
        $this->saveConfig($service, $config)->assertOk()->assertJsonPath('data.legacyCompleteEstimates', null);
        $pet = ['size'=>'small','grooming_preference'=>'kalbo','services'=>['package'=>$service->slug]];
        $this->assertSame(['min_minutes'=>40,'max_minutes'=>65], app(GroomingDuration::class)->estimate($pet, Service::all()));
        $config['haircuts'][0]['estimates']['small'] = ['enabled'=>false];
        $this->saveConfig($service, $config)->assertOk();
        $this->assertNull(app(GroomingDuration::class)->estimate($pet, Service::all()));
        $this->assertSame('Summer Cut', BookingPet::first()->grooming_preference_label);
    }

    public function test_cat_haircut_is_additive_only_when_requested_and_missing_time_is_unknown(): void
    {
        $service = $this->service('cat_full_grooming');
        $config = ['estimates'=>['small'=>$this->time('0','10')], 'haircuts'=>[
            ['name'=>'Owner requested style','isActive'=>true,'estimates'=>['small'=>$this->time('0','30')]],
        ]];
        $data = $this->saveConfig($service, $config)->assertOk()->json('data');
        $id = $data['haircutOptions'][0]['id'];
        $pet = ['size'=>'small','services'=>['package'=>$service->slug]];
        $calc = app(GroomingDuration::class);
        $this->assertSame(['min_minutes'=>10,'max_minutes'=>10], $calc->estimate($pet, Service::all()));
        $this->assertSame(['min_minutes'=>40,'max_minutes'=>40], $calc->estimate($pet+['haircut_requested'=>true,'grooming_preference'=>$id], Service::all()));
        $this->assertNull($calc->estimate($pet+['haircut_requested'=>true], Service::all()));
        $config['haircuts'] = [];
        $this->saveConfig($service, $config)->assertOk();
        $this->assertNull($calc->estimate($pet+['haircut_requested'=>true], Service::all()));
    }

    public function test_invalid_time_components_are_rejected_atomically(): void
    {
        $service = $this->service();
        foreach ([['-1','10'],['0','60'],['100','0'],['1.5','0'],['0','abc'],['0','0'],['0','123'],['1e1','0']] as [$h,$m]) {
            $this->saveConfig($service, ['estimates'=>['small'=>$this->time($h,$m)],'haircuts'=>[]])->assertUnprocessable();
        }
        $this->saveConfig($service, ['estimates'=>['small'=>$this->time('1','0',['1','0'])],'haircuts'=>[]])->assertUnprocessable();
        $this->assertNotEmpty($service->fresh()->legacy_complete_estimates);
        $this->saveConfig($service, ['estimates'=>['small'=>$this->time('1','0')],'haircuts'=>[]])->assertOk();
    }

    public function test_only_admin_can_write_and_staff_reads_active_catalogue(): void
    {
        $service = $this->service();
        Sanctum::actingAs(new User(['role'=>'staff','staff_type'=>'grooming','staff_subrole'=>'grooming_receptionist']));
        $this->saveConfig($service, ['estimates'=>[],'haircuts'=>[]])->assertForbidden();
        $response = $this->getJson('/api/admin/grooming/services/catalogue')->assertOk()->json();
        $this->assertSame('Summer Cut', $response['groomingPreferences']['regular_dog_grooming']['summer_cut'] ?? null);
    }

    public function test_selection_rejects_inactive_foreign_and_missing_cat_options(): void
    {
        $cat = $this->service('cat_full_grooming');
        $data = $this->saveConfig($cat, ['estimates'=>[], 'haircuts'=>[['name'=>'Requested style','isActive'=>true,'estimates'=>[]]]])->assertOk()->json('data');
        $id = $data['haircutOptions'][0]['id'];
        $selection = new GroomingServiceSelection;
        foreach ([null,'kalbo','fabricated'] as $invalid) {
            try { $selection->validate(['size'=>'small','haircut_requested'=>true,'grooming_preference'=>$invalid,'special_instructions'=>'Trim body'], $cat->slug, [], 'cat', 'pet'); $this->fail('Invalid haircut accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('pet.grooming_preference', $e->errors()); }
        }
        $selection->validate(['size'=>'small','haircut_requested'=>true,'grooming_preference'=>$id,'special_instructions'=>'Trim body'], $cat->slug, [], 'cat', 'pet');
        $selection->validate(['size'=>'small'], $cat->slug, [], 'cat', 'pet');
        $data['haircutOptions'][0]['isActive'] = false;
        $this->saveConfig($cat, ['estimates'=>[], 'haircuts'=>$data['haircutOptions']])->assertOk();
        $this->expectException(ValidationException::class);
        (new GroomingServiceSelection)->validate(['size'=>'small','haircut_requested'=>true,'grooming_preference'=>$id,'special_instructions'=>'Trim body'], $cat->slug, [], 'cat', 'pet');
    }

    public function test_haircut_rename_and_deactivation_preserve_ids_and_historical_names(): void
    {
        $regular = $this->service();
        $config = ['replaceLegacy'=>false,'estimates'=>[], 'haircuts'=>[
            ['id'=>'summer_cut','name'=>'Summer style','isActive'=>false,'estimates'=>[]],
        ]];
        $this->saveConfig($regular, $config)->assertOk();
        $this->assertSame('Summer Cut', BookingPet::first()->grooming_preference_label);
        $this->assertSame('Summer style', $regular->fresh()->haircut('summer_cut')['name']);
        $this->assertNull($regular->fresh()->haircut('summer_cut', true));
        $this->assertCount(4, $regular->fresh()->haircut_options);
        $this->assertNotEmpty($regular->fresh()->legacy_complete_estimates);
        $this->getJson('/api/grooming/services')->assertOk()->assertJsonPath('groomingPreferences.regular_dog_grooming', []);
    }

    public function test_size_specific_components_and_included_services_are_counted_once(): void
    {
        $regular = $this->service();
        $this->saveConfig($regular, ['estimates'=>['small'=>$this->time('0','10'),'medium'=>$this->time('1','0')],
            'haircuts'=>[['id'=>'kalbo','name'=>'Kalbo','isActive'=>true,'estimates'=>['small'=>$this->time('0','30'),'medium'=>$this->time('0','45')]]]])->assertOk();
        $extra = $this->service('anal_sac_draining');
        $this->putJson('/api/admin/grooming/services/'.$extra->service_id, ['name'=>$extra->service_name,'kind'=>'ala_carte','pricing_type'=>'fixed','amount'=>'150','isActive'=>true,
            'timeConfiguration'=>['estimates'=>['small'=>$this->time('0','15'),'medium'=>$this->time('0','20')],'haircuts'=>[]]])->assertOk();
        $calc = new GroomingDuration;
        $pet = ['size'=>'small','grooming_preference'=>'kalbo','services'=>['package'=>$regular->slug,'ala_carte'=>['nail_clipping','anal_sac_draining','anal_sac_draining']]];
        $this->assertSame(['min_minutes'=>55,'max_minutes'=>55], $calc->estimate($pet, Service::all()));
        $pet['size']='medium';
        $this->assertSame(['min_minutes'=>125,'max_minutes'=>125], $calc->estimate($pet, Service::all()));
        $pet['size']='large'; $this->assertNull($calc->estimate($pet, Service::all()));
        $this->assertSame(['min_minutes'=>25,'max_minutes'=>30], $calc->estimate(['size'=>'small','services'=>['ala_carte'=>['nail_clipping','anal_sac_draining']]],Service::all()));
    }

    public function test_unknown_and_cross_package_haircut_ids_and_unsupported_sizes_cannot_be_written(): void
    {
        $regular = $this->service();
        foreach (['puppy_cut','fabricated'] as $id) {
            $this->saveConfig($regular, ['estimates'=>[],'haircuts'=>[['id'=>$id,'name'=>'Unknown','isActive'=>true,'estimates'=>[]]]])->assertUnprocessable();
        }
        $cat = $this->service('cat_full_grooming');
        $this->saveConfig($cat, ['estimates'=>['extra_large'=>$this->time('0','10')],'haircuts'=>[]])->assertUnprocessable();
        $this->saveConfig($regular, ['estimates'=>['small'=>$this->time('99','59')],'haircuts'=>[]])->assertOk()
            ->assertJsonPath('data.timeEstimates.small', [5999,5999]);
    }
}
