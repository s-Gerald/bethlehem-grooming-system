<?php

namespace App\Rules;

use App\Support\PetInformation;
use App\Support\PetWeightSize;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPetWeight implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(
        private bool $requireSpecies = true,
        private bool $clinical = false,
        private ?float $existingWeight = null,
        private bool $allowExistingPetSelection = false,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $profile = preg_match('/^pets\.(\d+)\.weight$/', $attribute, $matches)
            ? ($this->data['pets'][(int) $matches[1]] ?? []) : $this->data;
        // Intake revalidates against the already loaded pet after checking ownership.
        if ($this->allowExistingPetSelection && ! empty($profile['pet_id'])) return;

        $message = PetWeightSize::weightValidationMessage($value, $this->clinical, $this->existingWeight);
        if ($message !== null) {
            $fail($message);
        } elseif ($this->requireSpecies && ! PetInformation::matches('weight', $this->existingWeight, $value)
            && PetWeightSize::sizeFor($profile['species'] ?? null, $value) === null) {
            $fail('Select Dog or Cat before entering a weight.');
        }
    }
}
