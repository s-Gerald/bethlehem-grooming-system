<?php

namespace App\Rules;

use App\Support\PhilippineMobile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidPhilippineMobile implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private bool $required = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($error = PhilippineMobile::entryError($value, $this->required)) $fail($error);
    }
}
