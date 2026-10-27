<?php

namespace App\Support;

/** Structural entry guardrails, never a drug-dose recommendation or diagnosis. */
final class ClinicClinicalRules
{
    public static function catalog(): array
    {
        static $catalog;
        return $catalog ??= json_decode(file_get_contents(resource_path('data/clinic-clinical-rules.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function validNumber(mixed $value, int $precision, float $min, float $max): bool
    {
        if (! is_scalar($value) || is_bool($value)) return false;
        $text = trim((string) $value);
        $pattern = $precision === 0 ? '/^\d+$/D' : '/^(?:\d+(?:\.\d{1,'.$precision.'})?|\.\d{1,'.$precision.'})$/D';
        return preg_match($pattern, $text) && is_finite((float) $text) && (float) $text >= $min && (float) $text <= $max;
    }

    public static function validVital(string $field, mixed $value, bool $exception = false): bool
    {
        $rule = self::catalog()['vitals'][$field];
        return self::validNumber($value, $rule['precision'], $exception ? 0 : $rule['min'], $exception ? $rule['storageMax'] : $rule['max']);
    }

    public static function hasVerification(?\App\Models\ClinicVital $vitals, string $field, mixed $value): bool
    {
        if ($value === null || $vitals?->{$field} === null || (float) $vitals->{$field} !== (float) $value) return false;
        foreach (array_reverse($vitals?->verification_history ?? []) as $entry) {
            if ($entry['field'] === $field) return (float) $entry['value'] === (float) $value;
        }
        return false;
    }

    public static function normalizeDoseUnit(string $unit): string
    {
        $text = trim($unit);
        foreach (self::catalog()['doseUnits'] as $definition) {
            if (strtolower($definition['value']) === strtolower($text)) return $definition['value'];
        }
        return $text;
    }

    public static function doseErrors(mixed $amount, mixed $unit, bool $required = false): array
    {
        $errors = [];
        $amountPresent = $amount !== null && $amount !== '';
        $unitPresent = is_string($unit) && trim($unit) !== '';
        if (($required || $unitPresent) && ! $amountPresent) $errors['dose_amount'] = 'Dose amount required.';
        if (($required || $amountPresent) && ! $unitPresent) $errors['dose_unit'] = 'Dose unit required.';
        $catalog = self::catalog();
        if ($amountPresent && (! self::validNumber($amount, $catalog['dosePrecision'], 0.001, $catalog['doseMax']))) {
            $errors['dose_amount'] = 'Enter 0.001–99,999.999, up to 3 decimals.';
        }
        $definition = collect($catalog['doseUnits'])->firstWhere('value', $unit);
        if ($amountPresent && ($definition['whole'] ?? false) && is_numeric($amount) && floor((float) $amount) !== (float) $amount) {
            $errors['dose_amount'] = 'Use a whole-number count for this unit.';
        }
        return $errors;
    }
}
