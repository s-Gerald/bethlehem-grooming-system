<?php

namespace App\Models;

use App\Support\PetWeightSize;
use App\Support\PetInformation;
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
            if ($this->body_condition && in_array(auth()->user()?->role, ['admin', 'staff'], true)) {
                $this->setBodyConditionVerification(true);
            }
            return $this->saveWithActivity($options);
        }
        $verifyBodyCondition = $options['verify_body_condition'] ?? false;
        if (array_intersect_key($this->getDirty(), PetInformation::FIELDS) === []
            && ! $this->isDirty('clinic_verified_fields') && ! $verifyBodyCondition) {
            return $this->saveWithActivity($options);
        }
        $save = function () use ($options, $verifyBodyCondition) {
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
                } elseif ($verifyBodyCondition) {
                    $changes['body_condition'] = $this->body_condition;
                }
                $this->setRawAttributes($current->getAttributes(), true);
                $this->setRawAttributes([...$current->getAttributes(), ...$changes]);
                if (array_key_exists('clinic_verified_fields', $changes)) {
                    $this->clinic_verified_fields = array_values(array_unique([
                        ...($current->clinic_verified_fields ?? []), ...array_diff($verified, ['body_condition']),
                    ]));
                }
                if ($verifyBodyCondition && $actor?->role !== 'customer') {
                    $this->setBodyConditionVerification($this->body_condition !== null);
                } elseif ($this->isDirty('body_condition')) {
                    $this->setBodyConditionVerification(false);
                }
                if (! $this->isDirty()) return true;

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
        'body_condition',
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
        'body_condition',
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

    public function hasVerifiedBodyCondition(): bool
    {
        return $this->body_condition !== null && in_array('body_condition', $this->clinic_verified_fields ?? [], true);
    }

    private function setBodyConditionVerification(bool $verified): void
    {
        $fields = $this->clinic_verified_fields ?? [];
        if ($verified === in_array('body_condition', $fields, true)) return;

        $this->clinic_verified_fields = $verified
            ? [...$fields, 'body_condition']
            : array_values(array_diff($fields, ['body_condition']));
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

    public function confirmClinicSize(string $size, array $profile = []): void
    {
        $this->size = $size;
        $this->clinic_verified_fields = array_values(array_unique([
            ...($this->clinic_verified_fields ?? []), 'size',
        ]));
        $this->fill(array_intersect_key($profile, ['body_condition' => true]));
        $this->save(['verify_body_condition' => array_key_exists('body_condition', $profile)]);
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
