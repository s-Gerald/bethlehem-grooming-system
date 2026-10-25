<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PetGroomer extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $fillable = ['first_name', 'on_duty'];

    protected $casts = ['on_duty' => 'boolean'];

    public function bookingPets()
    {
        return $this->hasMany(BookingPet::class, 'groomer_id');
    }
}
