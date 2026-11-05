<?php

namespace Tests\Support;

use App\Models\Service;

trait SeedsGroomingTimeCatalogue
{
    private function configuredTimeService(array $attributes): Service
    {
        $service = (new Service)->forceFill($attributes);
        $times = require database_path('data/grooming-legacy-estimates.php');
        $preferences = require database_path('data/grooming-legacy-haircuts.php');
        $service->time_estimates = $times['packages'][$service->slug]['sizes'] ??
            (isset($times['ala_carte'][$service->slug]) ? array_fill_keys(['small','medium','large','extra_large'], $times['ala_carte'][$service->slug]) : []);
        $service->haircut_options = collect($preferences[$service->slug] ?? [])->map(fn ($name, $id) =>
            ['id'=>$id, 'name'=>$name, 'isActive'=>true, 'estimates'=>[]])->values()->all();
        return $service;
    }

    private function seedGroomingTimeCatalogue(): void
    {
        foreach (Service::all() as $service) {
            $configured = $this->configuredTimeService($service->getAttributes());
            $values = [];
            foreach (['time_estimates','haircut_options'] as $field) {
                if ($service->$field === null) $values[$field] = $configured->$field;
            }
            if ($values) $service->forceFill($values)->save();
        }
    }
}
