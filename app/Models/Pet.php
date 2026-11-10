<?php

namespace App\Models;

use App\Support\PetWeightSize;
use App\Support\PetInformation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Pet extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically {
        save as private saveWithActivity;
    }

    public function save(array $options = [])
    {
        if (! $this->exists) {
            $this->customer_locked_fields = PetInformation::lockedFields($this);
            return $this->saveWithActivity($options);
        }
        if (array_intersect_key($this->getDirty(), PetInformation::FIELDS) === []
            && ! $this->isDirty('clinic_verified_fields')) {
            return $this->saveWithActivity($options);
        }
        $save = function () use ($options) {
            $actor = auth()->user();
            $changes = $this->getDirty();
            if ($this->exists) {
                $current = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                $verified = $this->clinic_verified_fields ?? [];
                if ($actor?->role === 'customer') {
                    abort_unless((int) $current->user_id === (int) $actor->user_id, 403);
                    $expectedNotes = array_key_exists('expected_medical_conditions', $options)
                        ? $options['expected_medical_conditions'] : $this->getOriginal('medical_conditions');
                    if (array_key_exists('medical_conditions', $changes)
                        && ! PetInformation::matches('medical_conditions', $current->medical_conditions, $changes['medical_conditions'])
                        && ! PetInformation::matches('medical_conditions', $current->medical_conditions, $expectedNotes)) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'expected_medical_conditions' => 'Care notes have changed. Reopen this pet and try again.',
                        ]);
                    }
                    $changes = PetInformation::customerAttributes($current, $changes);
                }
                $this->setRawAttributes($current->getAttributes(), true);
                $this->setRawAttributes([...$current->getAttributes(), ...$changes]);
                if (array_key_exists('clinic_verified_fields', $changes)) {
                    $this->clinic_verified_fields = array_values(array_unique([
                        ...($current->clinic_verified_fields ?? []), ...$verified,
                    ]));
                }
                $this->customer_locked_fields = array_values(array_unique([
                    ...PetInformation::lockedFields($current), ...PetInformation::lockedFields($this),
                ]));
            }
            $fields = array_values(array_filter(array_keys(PetInformation::FIELDS),
                fn ($field) => $this->isDirty($field)
                    && ! PetInformation::matches($field, $this->getOriginal($field), $this->$field)));
            $oldName = $this->getOriginal('pet_name');
            $notify = $this->exists && $this->user_id && $fields !== []
                && in_array($actor?->role, ['admin', 'staff'], true);
            $saved = $this->saveWithActivity($options);
            if ($saved && $notify) {
                CustomerNotification::create([
                    'user_id' => $this->user_id, 'pet_id' => $this->pet_id,
                    'type' => CustomerNotification::TYPE_PET_INFORMATION_UPDATED,
                    'message' => PetInformation::changeMessage($this, $fields, $oldName),
                    'is_read' => false, 'created_at' => now(),
                ]);
            }

            return $saved;
        };

        return $this->getConnection()->transactionLevel() ? $save() : $this->getConnection()->transaction($save);
    }

    public const CLINIC_VERIFIABLE_FIELDS = [
        'breed',
        'fur_type',
        'weight',
        'size',
    ];

    protected $table = 'pets';

    protected $primaryKey = 'pet_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'unregistered_customer_id',
        'pet_name',
        'species',
        'breed',
        'gender',
        'birthdate',
        'is_neutered',
        'neutered_date',
        'is_deceased',
        'deceased_date',
        'weight',
        'color',
        'size',
        'fur_type',
        'clinic_verified_fields',
        'medical_conditions',
        'is_archived',
    ];

    protected $casts = [
        'weight' => 'float',
        'clinic_verified_fields' => 'array',
        'customer_locked_fields' => 'array',
    ];

    public function scopeWithBodyCondition(Builder $query): Builder
    {
        // Match finalized medical history, including visits awaiting payment.
        return $query->addSelect(['body_condition_label' => ClinicAppointment::query()
            ->select('clinic_vitals.body_condition_score')
            ->join('clinic_vitals', 'clinic_vitals.clinic_appointment_id', '=', 'clinic_appointments.id')
            ->whereColumn('clinic_appointments.pet_id', 'pets.pet_id')
            ->whereIn('clinic_appointments.status', ['for_payment', 'completed'])
            ->whereHas('record')
            ->whereNotNull('clinic_vitals.body_condition_score')
            ->orderByDesc('clinic_appointments.appointment_date')
            ->orderByDesc('clinic_appointments.id')
            ->limit(1)]);
    }

    public function getBodyConditionLabelAttribute(mixed $score): ?string
    {
        return match ((int) $score) {
            1, 2, 3 => 'Thin',
            4, 5 => 'Ideal',
            6, 7 => 'Overweight',
            8, 9 => 'Obese',
            default => null,
        };
    }

    public function hasClinicVerifiedSize(): bool
    {
        return in_array('size', $this->clinic_verified_fields ?? [], true);
    }

    public function groomingSize(): ?string
    {
        return $this->hasClinicVerifiedSize() && $this->size
            ? $this->size
            : (PetWeightSize::sizeFor($this->species, $this->weight) ?? $this->size);
    }

    public function confirmClinicSize(string $size): void
    {
        $this->size = $size;
        $this->clinic_verified_fields = array_values(array_unique([
            ...($this->clinic_verified_fields ?? []), 'size',
        ]));
        $this->save();
    }

    public static function normalizeName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name)) ?: trim((string) $name);

        return preg_replace_callback(
            '/\p{L}/u',
            static fn (array $match): string => mb_strtoupper($match[0]),
            $normalized,
            1,
        ) ?? $normalized;
    }

    public function setPetNameAttribute(mixed $name): void
    {
        $this->attributes['pet_name'] = self::normalizeName($name);
    }

    // Pet belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function unregisteredCustomer()
    {
        return $this->belongsTo(UnregisteredCustomer::class, 'unregistered_customer_id');
    }

    public function vaccinationRecords()
    {
        return $this->hasMany(VaccinationRecord::class, 'pet_id', 'pet_id');
    }

}
