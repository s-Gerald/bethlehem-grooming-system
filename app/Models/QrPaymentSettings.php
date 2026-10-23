<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class QrPaymentSettings extends Model
{
    protected $table = 'qr_payment_settings';

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean', 'version' => 'integer'];

    protected $hidden = ['image_path', 'image_hash'];

    public function available(): bool
    {
        return $this->enabled && $this->merchant_name && $this->provider_name
            && $this->image_hash && $this->image_path && Storage::disk('local')->exists($this->image_path);
    }

    public function configuration(bool $admin = false): array
    {
        $available = $this->available();
        if (! $admin && ! $available) return ['enabled' => false];

        return [
            'enabled' => $admin ? $this->enabled : $available,
            'version' => $this->version,
            'merchant_name' => $this->merchant_name,
            'provider_name' => $this->provider_name,
            'has_image' => (bool) $this->image_path && Storage::disk('local')->exists($this->image_path),
        ];
    }
}
