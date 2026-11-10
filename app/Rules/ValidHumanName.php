<?php

namespace App\Rules;

use App\Support\HumanName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidHumanName implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private string $type = 'first_name', private bool $required = true, private ?string $unchanged = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '' && $value === $this->unchanged) return;
        if ($error = HumanName::error($value, $this->type, $this->required)) $fail($error);
    }
}
