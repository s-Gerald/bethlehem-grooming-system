<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerNotification extends Model
{
    public const TYPE_BOOKING_CANCELLED = 'booking_cancelled';
    public const TYPE_GROOMING_PRE_REGISTRATION_EXPIRED = 'grooming_pre_registration_expired';
    public const TYPE_GROOMING_NO_SHOW = 'grooming_no_show';

    public const TYPE_PET_INFORMATION_UPDATED = 'pet_information_updated';

    protected $table = 'customer_notifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'booking_id',
        'clinic_appointment_id',
        'pet_id',
        'type',
        'message',
        'is_read',
        'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'clinic_appointment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class, 'pet_id', 'pet_id');
    }

}
