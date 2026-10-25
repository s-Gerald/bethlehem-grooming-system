<?php

namespace App\Models;

use App\Support\PetWeightSize;
use Illuminate\Database\Eloquent\Model;

class Pet extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

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
    ];

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
        $sizeChanged = $this->size !== $size;
        $this->size = $size;
        $this->clinic_verified_fields = array_values(array_unique([
            ...($this->clinic_verified_fields ?? []), 'size',
        ]));
        $this->save();

        if ($sizeChanged && $this->user_id) {
            CustomerNotification::create([
                'user_id' => $this->user_id,
                'pet_id' => $this->pet_id,
                'type' => CustomerNotification::TYPE_PET_INFORMATION_UPDATED,
                'message' => "Information for {$this->pet_name} has been updated by Bethlehem Animal Clinic.",
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
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
