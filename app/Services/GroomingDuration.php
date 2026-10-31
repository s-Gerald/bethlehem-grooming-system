<?php

namespace App\Services;

use App\Models\BookingPet;
use Illuminate\Support\Collection;

class GroomingDuration
{
    public function estimate(array $pet, Collection $services): ?array
    {
        $catalog = $services->keyBy('slug');
        $package = $pet['services']['package'] ?? null;
        $extras = array_unique($pet['services']['ala_carte'] ?? []);
        if (! $package && ! $extras) return null;

        $min = $max = 0;
        $included = [];
        if ($package) {
            $service = $catalog->get($package);
            $rules = config('grooming_estimates.packages.'.$package);
            $size = $pet['size'] ?? '';
            $range = isset($rules['cuts']) ? ($rules['cuts'][$pet['grooming_preference'] ?? ''][$size] ?? null)
                : ($rules['sizes'][$size] ?? null);
            if (! $service || ! $range) return null;
            [$min, $max] = $range;
            $included = $services->whereIn('service_id', $service->included_ala_carte_ids ?? [])->pluck('slug')->all();
        }
        foreach (array_diff($extras, $included) as $slug) {
            $range = config('grooming_estimates.ala_carte.'.$slug);
            if (! $catalog->has($slug) || ! $range) return null;
            $min += $range[0];
            $max += $range[1];
        }
        return ['min_minutes' => $min, 'max_minutes' => $max];
    }

    /** booking and its services/pet must already be eager loaded by the caller. */
    public function forPet(BookingPet $pet, Collection $catalog): ?array
    {
        $lines = $pet->booking->bookingServices->where('booking_pet_id', $pet->booking_pet_id);
        $selected = $lines->map(fn ($line) => $line->service)->filter();
        if ($selected->count() !== $lines->count()) return null;
        return $this->estimate([
            'size' => $pet->confirmed_size ?? $pet->registered_size ?? $pet->pet?->groomingSize(),
            'grooming_preference' => $pet->grooming_preference,
            'services' => ['package' => $selected->firstWhere('kind', 'package')?->slug,
                'ala_carte' => $selected->where('kind', 'ala_carte')->pluck('slug')->all()],
        ], $catalog);
    }
}
