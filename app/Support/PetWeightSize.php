<?php

namespace App\Support;

use App\Models\Pet;

final class PetWeightSize
{
    private const MAX_WEIGHT_KG = 155.6;

    private const PROFILES = [
        'dog' => [
            ['size' => 'small', 'max' => 11.0],
            ['size' => 'medium', 'max' => 26.0],
            ['size' => 'large', 'max' => 51.0],
            ['size' => 'extra_large', 'max' => null],
        ],
        'cat' => [
            ['size' => 'small', 'max' => 5.0],
            ['size' => 'medium', 'max' => null],
        ],
    ];

    public static function sizeFor(mixed $species, mixed $weight): ?string
    {
        if (! self::isValidWeight($weight)) {
            return null;
        }

        $profile = self::profileFor($species);
        $numericWeight = (float) $weight;

        foreach ($profile as $range) {
            if ($range['max'] === null || $numericWeight < $range['max']) {
                return $range['size'];
            }
        }

        return null;
    }

    public static function validationMessage(mixed $species, mixed $weight = null): string
    {
        return self::weightValidationMessage($weight) ?? 'Select Dog or Cat before entering a weight.';
    }

    /** Validate submitted text before numeric conversion can discard decimal places. */
    public static function weightValidationMessage(mixed $weight): ?string
    {
        if ($weight === null || $weight === '') return null;
        if (! is_scalar($weight) || is_bool($weight)) return 'Enter a valid weight in kilograms.';
        $text = trim((string) $weight);
        if (! preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $text)
            || ! is_finite((float) $text)) return 'Enter a valid weight in kilograms.';
        $fraction = explode('.', $text, 2)[1] ?? '';
        if (strlen($fraction) > 2) return 'Enter weight using no more than 2 decimal places.';
        if ((float) $text <= 0) return 'Enter a weight greater than 0 kg.';
        if ((float) $text > self::MAX_WEIGHT_KG) return 'Weight cannot be more than 155.6 kg.';
        return null;
    }

    public static function isValidWeight(mixed $weight): bool
    {
        return is_numeric($weight) && is_finite((float) $weight)
            && (float) $weight > 0 && (float) $weight <= self::MAX_WEIGHT_KG;
    }

    public static function withComputedSize(array $pet): array
    {
        if (! array_key_exists('weight', $pet)
            || $pet['weight'] === null
            || $pet['weight'] === '') {
            return $pet;
        }

        $size = self::sizeFor($pet['species'] ?? null, $pet['weight']);

        if ($size !== null) {
            $pet['size'] = $size;
        }

        return $pet;
    }

    public static function withComputedSizeForUpdate(array $data, Pet $pet): array
    {
        $species = $data['species'] ?? $pet->species;
        $weight = $data['weight'] ?? $pet->weight;
        if (PetInformation::meaningful($pet->size)
            && PetInformation::matches('weight', $pet->weight, $weight)
            && PetInformation::matches('species', $pet->species, $species)) {
            return $data;
        }

        return self::withComputedSize([...$data, 'species' => $species, 'weight' => $weight]);
    }

    public static function isValidSize(mixed $species, mixed $size): bool
    {
        $normalizedSize = strtolower(trim((string) $size));

        foreach (self::profileFor($species) as $range) {
            if ($range['size'] === $normalizedSize) {
                return true;
            }
        }

        return false;
    }

    private static function profileFor(mixed $species): array
    {
        return self::PROFILES[self::normalizeSpecies($species)] ?? [];
    }

    private static function normalizeSpecies(mixed $species): string
    {
        return strtolower(trim((string) $species));
    }
}
