<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GroomingTimeConfiguration
{
    public function values(array $input, Service $service, array $sizes): array
    {
        Validator::make($input, [
            'estimates'=>['present','array:'.implode(',', $sizes)],
            'haircuts'=>['present','array','max:100'],
            'haircuts.*'=>['array:id,name,isActive,estimates'],
            'haircuts.*.id'=>['nullable','string','max:32','distinct'],
            'haircuts.*.name'=>['required','string','max:100'],
            'haircuts.*.isActive'=>['required','boolean'],
            'haircuts.*.estimates'=>['present','array:'.implode(',', $sizes)],
        ])->validate();
        if ($input['haircuts'] && ! $service->supportsHaircuts()) $this->invalid('haircuts', 'Haircuts are not supported by this package.');
        $options = [];
        foreach ($input['haircuts'] as $index => $option) {
            $id = $option['id'] ?? null;
            if ($id && ! $service->haircut($id)) $this->invalid("haircuts.$index.id", 'Select a haircut belonging to this package.');
            $name = trim($option['name']);
            if ($name === '') $this->invalid("haircuts.$index.name", 'Enter a haircut name.');
            $options[] = ['id'=>$id ?: str_replace('-', '', (string) Str::uuid()), 'name'=>$name,
                'isActive'=>(bool) $option['isActive'], 'estimates'=>$this->estimates($option['estimates'], "haircuts.$index.estimates")];
        }
        // Omitted options are deactivated, never deleted; IDs remain valid historical references.
        foreach ($service->haircut_options ?? [] as $existing) {
            if (! in_array($existing['id'], array_column($options, 'id'), true)) $options[] = [...$existing, 'isActive'=>false];
        }
        return ['time_estimates'=>$this->estimates($input['estimates'], 'estimates'),
            'haircut_options'=>$options];
    }

    private function estimates(array $rows, string $field): array
    {
        $result = [];
        foreach ($rows as $size => $row) {
            $path = "$field.$size";
            if (! is_array($row) || array_diff(array_keys($row), ['enabled','mode','min','max'])) $this->invalid($path, 'Enter a valid time.');
            if (! isset($row['enabled']) || ! is_bool($row['enabled'])) $this->invalid($path, 'Choose Set Time.');
            if (! $row['enabled']) { $result[$size] = null; continue; }
            if (! in_array($row['mode'] ?? null, ['fixed','range'], true)) $this->invalid($path, 'Choose Fixed or Range.');
            $minimum = $this->minutes($row['min'] ?? null, $path.'.min');
            $maximum = $row['mode'] === 'fixed' ? $minimum : $this->minutes($row['max'] ?? null, $path.'.max');
            if ($minimum <= 0) $this->invalid($path, 'Time must be greater than zero.');
            if ($row['mode'] === 'range' && $maximum <= $minimum) $this->invalid($path, 'Maximum time must be greater than minimum time.');
            $result[$size] = [$minimum, $maximum];
        }
        return $result;
    }

    private function minutes(mixed $pair, string $field): int
    {
        if (! is_array($pair) || array_diff(array_keys($pair), ['hours','minutes'])) $this->invalid($field, 'Enter Hours and Minutes.');
        foreach (['hours'=>99, 'minutes'=>59] as $unit=>$limit) {
            $value = $pair[$unit] ?? null;
            if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[0-9]{1,2}$/D', (string) $value) || (int) $value > $limit) {
                $this->invalid("$field.$unit", "Enter 0–$limit using at most two whole-number digits.");
            }
        }
        return (int) $pair['hours'] * 60 + (int) $pair['minutes'];
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['timeConfiguration.'.$field=>$message]);
    }
}
