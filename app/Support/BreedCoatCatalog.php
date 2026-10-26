<?php

namespace App\Support;

use RuntimeException;

final class BreedCoatCatalog
{
    private static ?array $catalogue = null;

    public static function requiresCoat(?string $breed, ?string $species): bool
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim((string) $breed)));

        return ! in_array($normalized, ['mixed breed / aspin', 'mixed breed / puspin', 'unknown breed'], true)
            && self::optionsFor($breed, $species) !== [];
    }

    public static function all(): array
    {
        if (self::$catalogue !== null) {
            return self::$catalogue;
        }

        $path = base_path('scripts/data/breed-coat-options.json');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('Breed and coat options could not be loaded.');
        }

        $catalogue = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($catalogue)) {
            throw new RuntimeException('Breed and coat options are invalid.');
        }

        return self::$catalogue = $catalogue;
    }

    public static function optionsFor(?string $breed, ?string $species): array
    {
        $breed = preg_replace('/\s+/', ' ', trim((string) $breed));

        if ($breed === '') {
            return [];
        }

        $catalogue = self::all();
        $normalizedSpecies = strtolower(trim((string) $species));
        $speciesKey = match ($normalizedSpecies) {
            'dog' => 'Dog',
            'cat' => 'Cat',
            default => null,
        };
        if ($speciesKey === 'Cat' && strcasecmp($breed, 'Mixed Breed / Aspin') === 0) {
            $breed = 'Mixed Breed / Puspin';
        }

        if ($speciesKey === null) {
            return [];
        }

        foreach ($catalogue[$speciesKey] ?? [] as $catalogueBreed => $options) {
            if (strcasecmp($catalogueBreed, $breed) === 0) {
                return array_values($options);
            }
        }

        return [];
    }
}
