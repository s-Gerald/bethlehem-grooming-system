<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingService extends Model
{
    protected $table = 'booking_services';
    protected $primaryKey = 'booking_service_id';
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'booking_pet_id',
        'service_id',
        'addon_id',
        'price_at_booking',
        'price_min_at_booking',
        'price_max_at_booking',
        'service_name_snapshot',
        'service_kind_snapshot',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }

    public function bookingPet()
    {
        return $this->belongsTo(BookingPet::class, 'booking_pet_id', 'booking_pet_id');
    }
}
