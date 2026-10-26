<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class GroomingServiceSelection
{
    private ?\Illuminate\Support\Collection $defaults = null;

    private ?\Illuminate\Support\Collection $catalogServices = null;

    public function services(): \Illuminate\Support\Collection
    {
        return $this->catalogServices ??= \App\Models\Service::all();
    }

    public function validate(array $pet, ?string $package, array $alaCarte, string $species, string $field): void
    {
        $services = $this->services()->keyBy('slug');
        $selected = array_merge($package ? [$package] : [], $alaCarte);
        if (! $selected || count($selected) !== count(array_unique($selected))) {
            throw ValidationException::withMessages([$field.'.services' => $selected ? 'Select each service once.' : 'Select at least one service.']);
        }
        $defaults = $this->defaults ??= collect(json_decode(file_get_contents(database_path('data/grooming-catalog.json')), true))->keyBy('id');
        foreach ($selected as $slug) {
            $service = $services->get($slug);
            $type = $service?->pet_type ?? ($defaults[$slug]['petType'] ?? 'all');
            if (! $service || ! $service->is_active || $service->kind !== ($slug === $package ? 'package' : 'ala_carte')
                || ($type !== 'all' && $type !== strtolower($species))) {
                throw ValidationException::withMessages([$field.'.services' => 'Select available services for this pet.']);
            }
        }
        $size = $pet['size'] ?? null;
        if ($package && $size && ! in_array($size, $services->get($package)->pricingSizes(), true)) {
            throw ValidationException::withMessages([$field.'.size' => 'Select a supported grooming size.']);
        }
        $included = $package ? ($services->get($package)->included_ala_carte_ids ?? []) : [];
        if (array_intersect($included, array_map(fn ($slug) => $services->get($slug)->service_id, $alaCarte))) {
            throw ValidationException::withMessages([$field.'.services' => 'A service is already included in the package.']);
        }
        $preferences = config('grooming_services.preferences.'.$package, []);
        $preference = $pet['grooming_preference'] ?? null;
        if (($preferences && ! isset($preferences[$preference])) || ($preference && ! isset($preferences[$preference]))) {
            throw ValidationException::withMessages([$field.'.grooming_preference' => 'Select a Haircut Preference.']);
        }
        $haircut = (bool) ($pet['haircut_requested'] ?? false);
        if ($haircut && ($package !== 'cat_full_grooming' || strtolower($species) !== 'cat')) {
            throw ValidationException::withMessages([$field.'.haircut_requested' => 'Include haircut is only available with Cat Full Grooming.']);
        }
        if (($haircut || ($package === 'deluxe_dog_grooming' && $preference === 'custom_hairstyle'))
            && ! preg_match('/[^\s\p{Z}\x{FEFF}]/u', $pet['special_instructions'] ?? '')) {
            $name = $pet['pet_name'] ?? 'this pet';
            throw ValidationException::withMessages([$field.'.special_instructions' => $haircut
                ? "Describe the requested haircut for {$name}." : "Describe the custom hairstyle for {$name}."]);
        }
    }
}
