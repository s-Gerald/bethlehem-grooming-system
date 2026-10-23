<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrPayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'received_amount' => 'decimal:2', 'transaction_at' => 'datetime', 'verified_at' => 'datetime',
    ];

    public function payable()
    {
        return $this->morphTo();
    }

    public function verificationDetails(): array
    {
        return [
            'external_reference' => $this->external_reference,
            'received_amount' => $this->received_amount,
            'transaction_at' => $this->transaction_at?->format('Y-m-d H:i:s'),
            'verification_source' => $this->verification_source,
            'verified_by' => $this->verified_by,
            'verified_by_name' => $this->verified_by_name,
            'verified_at' => $this->verified_at?->format('Y-m-d H:i:s'),
            'reference_unavailable_reason' => $this->reference_unavailable_reason,
            'merchant_name' => $this->merchant_name,
            'provider_name' => $this->provider_name,
            'settings_version' => $this->settings_version,
        ];
    }
}
