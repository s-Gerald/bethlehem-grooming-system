<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Walkin extends Model
{
    protected $table = 'walkins';

    protected $fillable = [
        'fname',
        'lname',
        'email',
        'phone',
        'sedation_consent',
        'terms_agreed',
        'user_id',
        'unregistered_customer_id',
        'appointment_type',
        'chief_complaint',
    ];

    protected $casts = [
        'sedation_consent' => 'boolean',
        'terms_agreed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function unregisteredCustomer()
    {
        return $this->belongsTo(UnregisteredCustomer::class, 'unregistered_customer_id');
    }

    public function booking()
    {
        return $this->hasOne(Booking::class, 'walkin_id', 'id');
    }

    public function clinicAppointment()
    {
        return $this->hasOne(ClinicAppointment::class, 'walkin_id', 'id');
    }
}
