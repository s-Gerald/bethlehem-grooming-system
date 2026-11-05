<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Services\GroomingDuration;
use Tests\TestCase;

class GroomingDurationTest extends TestCase
{
    use \Tests\Support\SeedsGroomingTimeCatalogue;
    private function catalog(): \Illuminate\Support\Collection
    {
        return collect([
            $this->configuredTimeService(['service_id' => 1, 'slug' => 'partial_grooming', 'kind' => 'package', 'included_ala_carte_ids' => [2, 3]]),
            $this->configuredTimeService(['service_id' => 2, 'slug' => 'nail_clipping', 'kind' => 'ala_carte']),
            $this->configuredTimeService(['service_id' => 3, 'slug' => 'facial_trimming', 'kind' => 'ala_carte']),
            $this->configuredTimeService(['service_id' => 4, 'slug' => 'anal_sac_draining', 'kind' => 'ala_carte']),
        ]);
    }

    public function test_only_component_times_are_used_even_if_an_old_model_contains_legacy_values(): void
    {
        $service=(new Service)->forceFill(['slug'=>'regular_dog_grooming','kind'=>'package',
            'time_estimates'=>['small'=>[45,60]], 'haircut_options'=>[
                ['id'=>'kalbo','name'=>'Kalbo','isActive'=>true,'estimates'=>['small'=>[30,45]]]],
            'legacy_complete_estimates'=>['kalbo'=>['small'=>[999,999]]]]);
        $pet=['size'=>'small','grooming_preference'=>'kalbo','services'=>['package'=>$service->slug]];
        $this->assertSame(['min_minutes'=>75,'max_minutes'=>105],(new GroomingDuration)->estimate($pet,collect([$service])));
        $service->time_estimates=[];
        $this->assertNull((new GroomingDuration)->estimate($pet,collect([$service])));
    }

    public function test_configured_package_and_haircut_ranges_are_added_independently_for_each_size(): void
    {
        foreach (['regular_dog_grooming','deluxe_dog_grooming','cat_full_grooming'] as $slug) {
            $base=['small'=>[45,60],'medium'=>[60,75],'large'=>[75,90],'extra_large'=>[90,105]];
            $cuts=['small'=>[30,45],'medium'=>[35,45],'large'=>[40,50],'extra_large'=>[45,60]];
            $service=new Service(['slug'=>$slug,'kind'=>'package','time_estimates'=>$base,'haircut_options'=>[
                ['id'=>'configured_style','name'=>'Configured style','isActive'=>true,'estimates'=>$cuts]]]);
            foreach (['small'=>[75,105],'medium'=>[95,120],'large'=>[115,140],'extra_large'=>[135,165]] as $size=>[$min,$max]) {
                $this->assertSame(['min_minutes'=>$min,'max_minutes'=>$max],(new GroomingDuration)->estimate([
                    'size'=>$size,'services'=>['package'=>$slug],'grooming_preference'=>'configured_style',
                    'haircut_requested'=>$slug==='cat_full_grooming'],collect([$service])));
            }
        }
    }

    public function test_current_package_inclusions_are_not_counted_twice(): void
    {
        $pet = ['size' => 'small', 'services' => ['package' => 'partial_grooming',
            'ala_carte' => ['nail_clipping', 'facial_trimming', 'anal_sac_draining', 'anal_sac_draining']]];
        $this->assertSame(['min_minutes' => 60, 'max_minutes' => 80], (new GroomingDuration)->estimate($pet, $this->catalog()));
        $this->assertSame(['min_minutes' => 25, 'max_minutes' => 45], (new GroomingDuration)->estimate([
            'size' => 'small',
            'services' => ['ala_carte' => ['nail_clipping', 'facial_trimming']],
        ], $this->catalog()));
    }

    public function test_unknown_rules_do_not_invent_durations_and_subjective_inputs_are_ignored(): void
    {
        $calculator = new GroomingDuration;
        $services = $this->catalog()->push(new Service(['slug' => 'new_service', 'kind' => 'ala_carte']));
        $pet = ['size' => 'small', 'services' => ['package' => 'partial_grooming']];
        $this->assertSame($calculator->estimate($pet, $services), $calculator->estimate($pet + [
            'breed' => 'Poodle', 'fur_type' => 'Curly', 'factors' => ['matted_tangled', 'extra_handling'],
            'haircut_requested' => true,
        ], $services));
        $this->assertNull($calculator->estimate(['services' => ['ala_carte' => ['new_service']]], $services));
        $this->assertNull($calculator->estimate(['size' => 'small', 'services' => ['package' => 'regular_dog_grooming']], $services));
        $this->assertNull($calculator->estimate([], $services));
    }

    public function test_standalone_services_use_migrated_times_and_unconfigured_cat_haircut_is_unknown(): void
    {
        $calculator = new GroomingDuration;
        foreach (['nail_clipping' => [10,15], 'ear_cleaning' => [10,15], 'facial_trimming' => [15,30],
            'anal_sac_draining' => [15,20], 'tooth_brushing' => [5,10]] as $slug => [$min,$max]) {
            $this->assertSame(['min_minutes' => $min, 'max_minutes' => $max], $calculator->estimate([
                'size' => 'small',
                'services' => ['ala_carte' => [$slug]],
            ], collect([$this->configuredTimeService(['slug' => $slug, 'kind' => 'ala_carte'])])));
        }
        $services = collect([$this->configuredTimeService(['slug' => 'cat_full_grooming', 'kind' => 'package'])]);
        $pet = ['size' => 'small', 'services' => ['package' => 'cat_full_grooming']];
        $this->assertSame(['min_minutes' => 90, 'max_minutes' => 90], $calculator->estimate($pet, $services));
        $this->assertNull($calculator->estimate($pet + ['haircut_requested' => true], $services));
        $pet['size'] = 'large';
        $this->assertNull($calculator->estimate($pet, $services));
        $this->assertNull($calculator->estimate(['size' => 'small', 'services' => ['package' => 'custom_package']],
            collect([new Service(['slug' => 'custom_package', 'kind' => 'package'])])));
    }
}

