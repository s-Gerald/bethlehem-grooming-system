<?php

namespace App\Rules;

use App\Support\HumanName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidHumanName implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private bool $required = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($error = HumanName::error($value, $this->required)) $fail($error);
    }
}
