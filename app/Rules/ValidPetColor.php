<?php

namespace App\Rules;

use App\Models\Pet;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

class ValidPetColor implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(
        private ?string $existingColor = null,
        private bool $allowExistingPetSelection = false,
    ) {}

    public static function normalize(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value ?? '') ?? '');

        return $value === '' ? null : $value;
    }

    /** Use the already authorized, loaded pet; never query from validation loops. */
    public static function validatedColor(array $data, ?Pet $pet = null, string $attribute = 'color'): ?string
    {
        if (! array_key_exists('color', $data)) {
            return $pet?->color;
        }
        $color = $data['color'];
        (new self($pet?->color))->validate('color', $color, static function ($message) use ($attribute) {
            throw ValidationException::withMessages([$attribute => $message]);
        });

        return $pet?->color !== null && self::normalize($color) === self::normalize($pet->color)
            ? $pet->color : self::normalize($color);
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || ! is_string($value)) return;
        $color = self::normalize($value);
        if ($this->existingColor !== null && $color === self::normalize($this->existingColor)) return;

        $selection = preg_match('/^pets\.(\d+)\.color$/', $attribute, $matches)
            ? ($this->data['pets'][(int) $matches[1]] ?? []) : $this->data;
        // Existing selections are checked again against the owned pet in the controller.
        if ($this->allowExistingPetSelection && ! empty($selection['pet_id'])) return;
        if ($color === null) return;
        if (mb_strlen($color) > 50) {
            $fail('Color must be 50 characters or fewer.');
        } elseif (preg_match('/\p{N}/u', $color)) {
            $fail('Color cannot contain numbers.');
        } elseif (! preg_match('/\p{L}/u', $color) || preg_match('~[^\p{L}\p{M} &/\-]~u', $color)) {
            $fail('Enter a color using letters, spaces, -, / or &.');
        }
    }
}
