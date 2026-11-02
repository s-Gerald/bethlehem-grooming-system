<?php

namespace App\Rules;

use App\Support\PetInformation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPetBirthdate implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(
        private ?string $existingBirthdate = null,
        private bool $allowExistingPetSelection = false,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->existingBirthdate !== null && PetInformation::matches('birthdate', $this->existingBirthdate, $value)) return;
        // Clinic intake resolves ownership and revalidates against the loaded pet before updating.
        if ($this->allowExistingPetSelection && ! empty($this->data['pet_id'])) return;
        if (! is_string($value) || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $fail('Enter a valid birthdate.');

            return;
        }

        $today = CarbonImmutable::today('Asia/Manila');
        if ($value > $today->toDateString()) {
            $fail('Birthdate cannot be in the future.');
        } elseif ($value < $today->subYearsNoOverflow(40)->toDateString()) {
            $fail('Enter a birthdate within the last 40 years.');
        }
    }
}
