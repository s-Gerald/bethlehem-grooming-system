<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPet extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    public const GROOMING_STATE_NOT_STARTED = 'not_started';

    public const GROOMING_STATE_IN_PROGRESS = 'in_progress';

    public const GROOMING_STATE_FINISHED = 'finished';

    public const GROOMING_STATE_CANCELLED = 'cancelled';

    public const GROOMING_STATES = [
        self::GROOMING_STATE_NOT_STARTED,
        self::GROOMING_STATE_IN_PROGRESS,
        self::GROOMING_STATE_FINISHED,
        self::GROOMING_STATE_CANCELLED,
    ];

    protected $table = 'booking_pets';

    protected $primaryKey = 'booking_pet_id';

    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'pet_id',
        'registered_size',
        'confirmed_size',
        'pet_queue_date',
        'pet_queue_number',
        'special_instructions',
        'haircut_requested',
        'groomer_id',
        'grooming_start_time',
        'grooming_end_time',
        'grooming_state',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
        'grooming_preference',
        'grooming_estimate_min',
        'grooming_estimate_max',
        'grooming_estimate_factors',
    ];

    protected $casts = [
        'haircut_requested' => 'boolean',
        'cancelled_at' => 'datetime',
        'grooming_estimate_min' => 'integer',
        'grooming_estimate_max' => 'integer',
        'grooming_estimate_factors' => 'array',
        'pet_queue_date' => 'date',
        'pet_queue_number' => 'integer',
        'grooming_start_time' => 'datetime',
        'grooming_end_time' => 'datetime',
    ];

    // BookingPet belongs to a booking
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    // BookingPet belongs to a pet
    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

    public function groomer()
    {
        return $this->belongsTo(PetGroomer::class, 'groomer_id');
    }

    public static function isValidGroomingState(string $state): bool
    {
        return in_array($state, self::GROOMING_STATES, true);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by', 'user_id');
    }

    public function canCancelGrooming(): bool
    {
        return $this->grooming_state === self::GROOMING_STATE_NOT_STARTED
            && $this->grooming_start_time === null
            && $this->grooming_end_time === null;
    }

    public function canUndoGroomingStart(): bool
    {
        return $this->grooming_state === self::GROOMING_STATE_IN_PROGRESS
            && $this->grooming_start_time !== null
            && $this->grooming_end_time === null;
    }
}
