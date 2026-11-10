<?php

namespace App\Support;

use App\Models\ClinicAppointment;
use App\Models\Pet;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PetBodyCondition
{
    public const VALUES = ['thin', 'ideal', 'overweight', 'obese'];

    public static function rules(): array
    {
        return ['nullable', Rule::in(self::VALUES)];
    }

    public static function fromScore(mixed $score): ?string
    {
        return match ((int) $score) {
            1, 2, 3 => 'thin',
            4, 5 => 'ideal',
            6, 7 => 'overweight',
            8, 9 => 'obese',
            default => null,
        };
    }

    public static function latestAssessment(): Builder
    {
        return DB::table('clinic_appointments as visits')
            ->join('clinic_vitals as vitals', 'vitals.clinic_appointment_id', '=', 'visits.id')
            ->whereIn('visits.status', ['for_payment', 'completed'])
            ->whereBetween('vitals.body_condition_score', [1, 9])
            ->whereExists(fn ($records) => $records->from('clinic_records')->whereColumn('clinic_appointment_id', 'visits.id'))
            ->orderByDesc('visits.appointment_date')->orderByDesc('visits.id');
    }

    /** Called inside the visit's existing clinical transaction. */
    public static function synchronize(ClinicAppointment $visit, mixed $score): void
    {
        $condition = self::fromScore($score);
        if (! $condition || ! $visit->pet_id) return;

        $pet = Pet::query()->lockForUpdate()->find($visit->pet_id);
        if (! $pet) return;

        $latestVisit = self::latestAssessment()->where('visits.pet_id', $pet->pet_id)->value('visits.id');
        if ((int) $latestVisit !== (int) $visit->id) return;

        $pet->update(['body_condition' => $condition], ['verify_body_condition' => true]);
    }
}
