<?php

namespace App\Rules;

use App\Models\Pet;
use App\Support\BreedCoatCatalog;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

class ValidBreedCoat implements DataAwareRule, ValidationRule
{
    // Run for missing and nullable values as well as supplied coat choices.
    public bool $implicit = true;

    private array $data = [];

    public function __construct(private bool $allowExistingPetSelection = false) {}

    /** Validate against the already-loaded profile, without querying inside pet loops. */
    public static function validatedCoat(array $data, ?Pet $pet = null, string $attribute = 'fur_type'): ?string
    {
        $breed = $data['breed'] ?? $pet?->breed;
        $species = $pet?->species ?? $data['species'] ?? null;
        $normalize = static fn ($value) => strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));
        $breedChanged = $pet && $normalize($breed) !== $normalize($pet->breed);
        $coat = $data['fur_type'] ?? $pet?->fur_type;

        if ($pet && ! $breedChanged && empty($data['fur_type'])) {
            return $pet->fur_type;
        }

        if ($breedChanged && empty($data['fur_type']) && ! in_array($coat, BreedCoatCatalog::optionsFor($breed, $species), true)) {
            $coat = null;
        }

        (new self)->setData(['breed' => $breed, 'species' => $species])->validate('fur_type', $coat,
            static function ($message) use ($attribute) {
                throw ValidationException::withMessages([$attribute => $message]);
            });

        return $coat;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$breed, $species] = $this->relatedBreedAndSpecies($attribute);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            // Registration can select an existing profile without editing it.
            $pet = preg_match('/^pets\.(\d+)\.fur_type$/', $attribute, $matches)
                ? ($this->data['pets'][(int) $matches[1]] ?? []) : $this->data;
            if ((! $this->allowExistingPetSelection || empty($pet['pet_id'])) && BreedCoatCatalog::requiresCoat($breed, $species)) {
                $fail('Select a fur type.');
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $options = BreedCoatCatalog::optionsFor($breed, $species);

        if (! in_array($value, $options, true)) {
            $fail('The selected fur type is not available for this breed.');
        }
    }

    private function relatedBreedAndSpecies(string $attribute): array
    {
        if (preg_match('/^pets\.(\d+)\.fur_type$/', $attribute, $matches)) {
            $pet = $this->data['pets'][(int) $matches[1]] ?? [];

            return [$pet['breed'] ?? null, $pet['species'] ?? null];
        }

        return [$this->data['breed'] ?? null, $this->data['species'] ?? null];
    }
}
