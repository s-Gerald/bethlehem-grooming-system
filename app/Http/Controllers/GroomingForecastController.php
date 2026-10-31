<?php

namespace App\Http\Controllers;

use App\Models\Pet;
use App\Services\GroomingQueueForecast;
use App\Support\PetWeightSize;
use Illuminate\Http\Request;

class GroomingForecastController extends Controller
{
    public function customer(Request $request, GroomingQueueForecast $forecast)
    {
        return $this->preview($request, $forecast, false);
    }

    public function staff(Request $request, GroomingQueueForecast $forecast)
    {
        return $this->preview($request, $forecast, true);
    }

    private function preview(Request $request, GroomingQueueForecast $forecast, bool $walkIn)
    {
        $data = $request->validate([
            'durations_only' => 'sometimes|boolean', 'pets' => 'required|array|min:1|max:10',
            'pets.*.pet_id' => 'nullable|integer', 'pets.*.species' => 'required|in:dog,cat',
            'pets.*.size' => 'nullable|in:small,medium,large,extra_large', 'pets.*.weight' => 'nullable|numeric|min:0',
            'pets.*.groomer_id' => 'nullable|integer', 'pets.*.grooming_preference' => 'nullable|string|max:32',
            'pets.*.services' => 'sometimes|array', 'pets.*.services.package' => 'nullable|string|max:100',
            'pets.*.services.ala_carte' => 'sometimes|array|max:100', 'pets.*.services.ala_carte.*' => 'string|max:100',
        ]);
        $ids = array_filter(array_column($data['pets'], 'pet_id'));
        $saved = $ids ? Pet::whereIn('pet_id', $ids)
            ->when(! $walkIn, fn ($query) => $query->where('user_id', $request->user()->user_id))->get()->keyBy('pet_id') : collect();
        foreach ($data['pets'] as &$pet) {
            if (! empty($pet['pet_id'])) {
                abort_unless($saved->has($pet['pet_id']), 404);
                $record = $saved[$pet['pet_id']];
                $pet['species'] = strtolower($record->species);
                $pet['size'] = $record->hasClinicVerifiedSize() ? $record->groomingSize()
                    : (PetWeightSize::withComputedSize(['species' => $record->species,
                        'weight' => $pet['weight'] ?? $record->weight])['size'] ?? $record->groomingSize());
            } elseif (isset($pet['weight'])) {
                $pet = PetWeightSize::withComputedSize($pet);
            }
        }
        unset($pet);
        return response()->json($forecast->preview($data['pets'], $walkIn, $data['durations_only'] ?? false))
            ->header('Cache-Control', 'no-store, private');
    }
}
