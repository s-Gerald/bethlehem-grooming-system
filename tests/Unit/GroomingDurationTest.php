<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Services\GroomingDuration;
use Tests\TestCase;

class GroomingDurationTest extends TestCase
{
    private function catalog(): \Illuminate\Support\Collection
    {
        return collect([
            (new Service)->forceFill(['service_id' => 1, 'slug' => 'partial_grooming', 'kind' => 'package', 'included_ala_carte_ids' => [2, 3]]),
            (new Service)->forceFill(['service_id' => 2, 'slug' => 'nail_clipping', 'kind' => 'ala_carte']),
            (new Service)->forceFill(['service_id' => 3, 'slug' => 'facial_trimming', 'kind' => 'ala_carte']),
            (new Service)->forceFill(['service_id' => 4, 'slug' => 'anal_sac_draining', 'kind' => 'ala_carte']),
        ]);
    }

    public function test_historical_size_and_cut_ranges_are_preserved(): void
    {
        $calculator = new GroomingDuration;
        $cases = [
            ['partial_grooming', null, [[45,60],[60,75],[75,90],[90,105]]],
            ['bath_and_go', null, [[45,45],[60,60],[75,75],[90,90]]],
            ['regular_dog_grooming', 'summer_cut', [[75,75],[75,90],[90,105],[105,120]]],
            ['regular_dog_grooming', 'kalbo', [[75,75],[75,90],[90,105],[105,120]]],
            ['regular_dog_grooming', 'semi_kalbo', [[75,90],[90,90],[105,120],[120,120]]],
            ['regular_dog_grooming', 'regular_trim', [[90,90],[90,105],[120,120],[120,150]]],
            ['deluxe_dog_grooming', 'puppy_cut', [[90,105],[105,120],[120,150],[150,180]]],
            ['deluxe_dog_grooming', 'custom_hairstyle', [[120,150],[135,180],[150,210],[180,240]]],
            ['cat_full_grooming', null, [[90,90],[105,105]]],
        ];
        foreach ($cases as [$package, $cut, $ranges]) {
            foreach ($ranges as $i => [$min, $max]) {
                $services = collect([(new Service)->forceFill(['service_id' => 1, 'slug' => $package, 'kind' => 'package'])]);
                $this->assertSame(['min_minutes' => $min, 'max_minutes' => $max], $calculator->estimate([
                    'size' => ['small','medium','large','extra_large'][$i], 'services' => ['package' => $package],
                    'grooming_preference' => $cut,
                ], $services));
            }
        }
    }

    public function test_current_package_inclusions_are_not_counted_twice(): void
    {
        $pet = ['size' => 'small', 'services' => ['package' => 'partial_grooming',
            'ala_carte' => ['nail_clipping', 'facial_trimming', 'anal_sac_draining', 'anal_sac_draining']]];
        $this->assertSame(['min_minutes' => 60, 'max_minutes' => 80], (new GroomingDuration)->estimate($pet, $this->catalog()));
        $this->assertSame(['min_minutes' => 25, 'max_minutes' => 45], (new GroomingDuration)->estimate([
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

    public function test_standalone_services_and_cat_haircut_use_historical_rules_only(): void
    {
        $calculator = new GroomingDuration;
        foreach (['nail_clipping' => [10,15], 'ear_cleaning' => [10,15], 'facial_trimming' => [15,30],
            'anal_sac_draining' => [15,20], 'tooth_brushing' => [5,10]] as $slug => [$min,$max]) {
            $this->assertSame(['min_minutes' => $min, 'max_minutes' => $max], $calculator->estimate([
                'services' => ['ala_carte' => [$slug]],
            ], collect([new Service(['slug' => $slug, 'kind' => 'ala_carte'])])));
        }
        $services = collect([new Service(['slug' => 'cat_full_grooming', 'kind' => 'package'])]);
        $pet = ['size' => 'small', 'services' => ['package' => 'cat_full_grooming']];
        $this->assertSame(['min_minutes' => 90, 'max_minutes' => 90], $calculator->estimate($pet + ['haircut_requested' => true], $services));
        $this->assertSame($calculator->estimate($pet, $services), $calculator->estimate($pet + ['haircut_requested' => true], $services));
        $pet['size'] = 'large';
        $this->assertNull($calculator->estimate($pet, $services));
        $this->assertNull($calculator->estimate(['size' => 'small', 'services' => ['package' => 'custom_package']],
            collect([new Service(['slug' => 'custom_package', 'kind' => 'package'])])));
    }
}

