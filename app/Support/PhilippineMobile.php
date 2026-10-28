<?php

namespace App\Support;

final class PhilippineMobile
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) return $value;
        // Preserve supported presentation characters, but never discard letters or extensions.
        $phone = preg_replace('/[\s()-]+/', '', trim($value));
        if (preg_match('/^\+?63([89][0-9]{9})$/D', $phone, $matches)) return '0'.$matches[1];
        if (preg_match('/^[89][0-9]{9}$/D', $phone)) return '0'.$phone;

        return $phone;
    }

    public static function rules(bool $required = true): array
    {
        return [new \App\Rules\ValidPhilippineMobile($required)];
    }

    public static function formatError(mixed $value, bool $required = true): ?string
    {
        if ($value === null || $value === '') return $required ? 'Enter a mobile number.' : null;
        if (! is_string($value) || ! preg_match('/^[0-9]{11}$/D', $value)) return 'Enter an 11-digit mobile number.';
        if (! preg_match('/^0[89]/', $value)) return 'Enter a valid Philippine mobile number.';

        return null;
    }

    public static function entryError(mixed $value, bool $required = true): ?string
    {
        if ($error = self::formatError($value, $required)) return $error;
        if ($value === null || $value === '') return null;
        $policy = config('philippine_mobile') ?? require base_path('config/philippine_mobile.php');

        return in_array(substr($value, 0, 4), $policy['prefixes'], true) ? null : 'Enter a valid Philippine mobile number.';
    }
}
