<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class ClinicConcerns
{
    public const OPTIONS = [
        'Routine check-up',
        'Vaccination',
        'Vomiting',
        'Diarrhea',
        'Not eating',
        'Skin / itching',
        'Ear problem',
        'Eye problem',
        'Coughing / sneezing',
        'Limping / injury',
        'Other',
    ];

    public static function rules(): array
    {
        return [
            'common_concerns' => ['required', 'array', 'min:1', 'max:'.count(self::OPTIONS)],
            'common_concerns.*' => ['required', 'string', 'distinct', Rule::in(self::OPTIONS)],
            'chief_complaint' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function summary(array $concerns, ?string $details): string
    {
        $details = trim($details ?? '');

        return implode(', ', $concerns).($details !== '' ? "\n{$details}" : '');
    }
}
