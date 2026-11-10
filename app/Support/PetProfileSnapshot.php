<?php

namespace App\Support;

use App\Models\BookingPet;
use App\Models\Pet;
use Illuminate\Database\Eloquent\Builder;

/** Service-owned historical metadata. Never refreshed by a Pet profile edit. */
final class PetProfileSnapshot
{
    private const STAGES = ['intake', 'registration', 'legacy'];

    public const FIELDS = [
        'pet_name', 'species', 'breed', 'gender', 'birthdate', 'color', 'fur_type', 'size',
        'weight', 'body_condition', 'medical_conditions', 'is_neutered', 'neutered_date',
        'is_deceased', 'deceased_date',
    ];

    public static function capture(Pet|array|null $pet, string $source = 'registration'): array
    {
        $attributes = $pet instanceof Pet ? $pet->attributesToArray() : ($pet ?? []);
        $profile = array_replace(array_fill_keys(self::FIELDS, null), array_intersect_key($attributes, array_flip(self::FIELDS)));
        foreach (['is_neutered', 'is_deceased'] as $field) {
            if ($profile[$field] !== null) $profile[$field] = (bool) $profile[$field];
        }
        if ($profile['weight'] !== null) $profile['weight'] = (float) $profile['weight'];
        $verified = $attributes['clinic_verified_fields'] ?? [];
        if (is_string($verified)) $verified = json_decode($verified, true);

        return [
            'captured_at' => now()->toIso8601String(),
            'source' => $source,
            ...$profile,
            // Current verification is not evidence of verification during an older visit.
            'verified_fields' => $source === 'legacy_baseline' ? [] : array_values(array_unique(array_intersect(
                is_array($verified) ? $verified : [], Pet::CLINIC_VERIFIABLE_FIELDS,
            ))),
        ];
    }

    public static function registration(Pet $pet, bool $intake = false): array
    {
        $snapshot = ['version' => 1, 'registration' => self::capture($pet)];
        return $intake ? self::intake($snapshot, $pet) : $snapshot;
    }

    public static function intake(?array $snapshot, ?Pet $pet): array
    {
        $snapshot ??= ['version' => 1];
        // Repeated check-in requests cannot refresh the original physical-intake facts.
        if (! isset($snapshot['intake'])) $snapshot['intake'] = self::capture($pet, 'intake');
        return $snapshot;
    }

    public static function withoutIntake(?array $snapshot): ?array
    {
        if ($snapshot !== null) unset($snapshot['intake']);
        return $snapshot;
    }

    public static function resolve(?array $snapshot): array
    {
        foreach (self::STAGES as $stage) {
            if (is_array($snapshot[$stage] ?? null)) return $snapshot[$stage];
        }
        return [];
    }

    /** Unsaved presentation model; no live relation lookup or profile-update side effects. */
    public static function pet(?array $snapshot, ?int $petId): ?Pet
    {
        $profile = self::resolve($snapshot);
        if ($profile === []) return null;
        $pet = new Pet;
        $pet->setRawAttributes([
            ...array_intersect_key($profile, array_flip(self::FIELDS)),
            'pet_id' => $petId,
            'clinic_verified_fields' => json_encode($profile['verified_fields'] ?? []),
        ], true);
        return $pet;
    }

    public static function groomingSize(BookingPet $pet): ?string
    {
        return $pet->confirmed_size ?? $pet->registered_size ?? (self::resolve($pet->pet_profile_snapshot)['size'] ?? null);
    }

    /** Match the selected stage, including explicit null values, exactly as resolve() does. */
    public static function whereIdentity(Builder $query, string $search, array $fields): void
    {
        $query->where(function (Builder $identity) use ($search, $fields) {
            $earlier = [];
            foreach (self::STAGES as $stage) {
                $identity->orWhere(function (Builder $selected) use ($search, $fields, $stage, $earlier) {
                    foreach ($earlier as $missing) $selected->whereNull("pet_profile_snapshot->{$missing}");
                    $selected->whereNotNull("pet_profile_snapshot->{$stage}")
                        ->where(function (Builder $values) use ($stage, $fields, $search) {
                            foreach ($fields as $field) {
                                $column = $values->getQuery()->getGrammar()->wrap("pet_profile_snapshot->{$stage}->{$field}");
                                $values->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
                            }
                        });
                });
                $earlier[] = $stage;
            }
        });
    }
}
