<?php

namespace App\Rules;

use App\Support\AccountEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidAccountEmail implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private bool $required = true, private ?int $maxLength = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($error = AccountEmail::entryError($value, $this->required, $this->maxLength)) $fail($error);
    }
}
