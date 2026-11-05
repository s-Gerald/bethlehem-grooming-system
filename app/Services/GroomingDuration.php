<?php

namespace App\Services;

use App\Models\BookingPet;
use Illuminate\Support\Collection;

class GroomingDuration
{
    public function estimate(array $pet, Collection $services, bool $historical = false): ?array
    {
        $catalog = $services->keyBy('slug');
        $package = $pet['services']['package'] ?? null;
        $extras = array_unique($pet['services']['ala_carte'] ?? []);
        if (! $package && ! $extras) return null;

        $min = $max = 0;
        $size = $pet['size'] ?? '';
        $included = [];
        if ($package) {
            $service = $catalog->get($package);
            $preference = $pet['grooming_preference'] ?? null;
            $catHaircut = $package === 'cat_full_grooming' && ! empty($pet['haircut_requested']);
            $haircutRequired = $package === 'cat_full_grooming' ? $catHaircut
                : (bool) ($service?->activeHaircuts() || $preference);
            $option = $service?->haircut($preference, ! $historical);
            if ($preference && $haircutRequired && ! $option) return null;
            $range = $service?->time_estimates[$size] ?? null;
            if (! $service || ! $range) return null;
            [$min, $max] = $range;
            if ($haircutRequired) {
                $cutRange = $option['estimates'][$size] ?? null;
                if (! $cutRange) return null;
                $min += $cutRange[0]; $max += $cutRange[1];
            }
            $included = $services->whereIn('service_id', $service->included_ala_carte_ids ?? [])->pluck('slug')->all();
        }
        foreach (array_diff($extras, $included) as $slug) {
            $range = $catalog->get($slug)?->time_estimates[$size] ?? null;
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
            'haircut_requested' => $pet->haircut_requested,
            'services' => ['package' => $selected->firstWhere('kind', 'package')?->slug,
                'ala_carte' => $selected->where('kind', 'ala_carte')->pluck('slug')->all()],
        ], $catalog, true);
    }
}
