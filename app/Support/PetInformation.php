<?php

namespace App\Support;

use App\Models\Pet;
use Illuminate\Validation\ValidationException;

final class PetInformation
{
    public const FIELDS = [
        'pet_name' => 'name', 'species' => 'species', 'breed' => 'breed',
        'fur_type' => 'fur type', 'gender' => 'gender', 'birthdate' => 'birthdate',
        'weight' => 'weight', 'size' => 'size', 'color' => 'color',
        'medical_conditions' => 'allergies and special care notes',
        'is_neutered' => 'neutered status', 'neutered_date' => 'neutered date',
        'is_deceased' => 'deceased status', 'deceased_date' => 'deceased date',
    ];

    public const CUSTOMER_EDITABLE_FIELDS = [
        'pet_name', 'species', 'breed', 'fur_type', 'gender', 'birthdate', 'size', 'color', 'medical_conditions',
    ];

    public static function lockedFields(Pet $pet): array
    {
        return array_values(array_diff(array_unique([
            ...($pet->customer_locked_fields ?? []),
            ...array_filter(self::CUSTOMER_EDITABLE_FIELDS, fn ($field) => self::meaningful($pet->$field)),
        ]), ['medical_conditions', 'weight']));
    }

    public static function meaningful(mixed $value): bool
    {
        if (is_array($value) || is_object($value)) return true;
        return $value !== null && trim((string) $value) !== '';
    }

    public static function matches(string $field, mixed $before, mixed $after): bool
    {
        if (is_array($before) || is_object($before) || is_array($after) || is_object($after)) return false;
        if (in_array($field, ['is_neutered', 'is_deceased'], true)) return (bool) $before === (bool) $after;
        if (! self::meaningful($before) || ! self::meaningful($after)) {
            return ! self::meaningful($before) && ! self::meaningful($after);
        }
        if ($field === 'weight') {
            return is_numeric($before) && is_numeric($after) && (float) $before === (float) $after;
        }
        if ($field === 'pet_name') return Pet::normalizeName($before) === Pet::normalizeName($after);
        $normalize = static fn ($value) => trim((string) $value);

        return in_array($field, ['species', 'gender'], true)
            ? strcasecmp($normalize($before), $normalize($after)) === 0
            : $normalize($before) === $normalize($after);
    }

    /** Called with the latest profile locked in the same transaction as save. */
    public static function customerAttributes(Pet $pet, array $attributes, string $prefix = ''): array
    {
        unset($attributes['weight']);
        $verified = $pet->clinic_verified_fields ?? [];
        $locked = self::lockedFields($pet);
        $measurementsVerified = array_intersect(['weight', 'size'], $verified) !== [];
        $errors = [];
        foreach (self::FIELDS as $field => $label) {
            if (! array_key_exists($field, $attributes)) continue;
            if (self::matches($field, $pet->$field, $attributes[$field])) {
                // Keep historical precision, spelling and casing for unchanged fields.
                $attributes[$field] = $pet->$field;
                continue;
            }
            if ($field === 'medical_conditions') continue;
            if (in_array($field, ['pet_name', 'species'], true)
                || in_array($field, $locked, true) || in_array($field, $verified, true)
                || ($measurementsVerified && $field === 'size')) {
                $errors[$prefix.$field] = 'Saved details can only be changed by clinic staff.';
            }
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);

        return $attributes;
    }

    public static function changeMessage(Pet $pet, array $fields, mixed $oldName): string
    {
        $renamed = in_array('pet_name', $fields, true);
        $otherFields = array_values(array_diff($fields, ['pet_name']));
        $labels = array_map(fn ($field) => self::FIELDS[$field], $otherFields);
        $list = count($labels) < 3 ? implode(' and ', $labels)
            : implode(', ', array_slice($labels, 0, -1)).', and '.end($labels);
        $message = $renamed
            ? "Bethlehem Animal Clinic changed your pet's name from {$oldName} to {$pet->pet_name}"
            : "Bethlehem Animal Clinic updated {$pet->pet_name}'s {$list}";
        if ($renamed && $list !== '') $message .= " and updated {$list}";

        return $message.'.';
    }
}
