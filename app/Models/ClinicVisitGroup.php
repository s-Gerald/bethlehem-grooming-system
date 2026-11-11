<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ClinicVisitGroup extends Model
{
    public const MAX_PATIENTS = 13;

    protected $fillable = [
        'user_id', 'walkin_id', 'source', 'service_day', 'total_amount',
        'payment_method', 'cash_received', 'paid_by_user_id', 'paid_at', 'gateway_payment_id',
    ];

    protected $casts = [
        'service_day' => 'date',
        'total_amount' => 'decimal:2',
        'cash_received' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function appointments()
    {
        return $this->hasMany(ClinicAppointment::class);
    }

    public function gatewayPayment()
    {
        return $this->belongsTo(GatewayPayment::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id', 'user_id');
    }

    public static function patientNames(Collection $pets): string
    {
        $names = $pets->take(4)->pluck('pet_name')->implode(', ');

        return $pets->count() > 4 ? $names.' +'.($pets->count() - 4).' more' : $names;
    }
}
