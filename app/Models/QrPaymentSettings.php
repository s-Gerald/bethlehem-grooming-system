<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class QrPaymentSettings extends Model
{
    protected $table = 'qr_payment_settings';

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean', 'version' => 'integer', 'accepted_methods' => 'array'];

    protected $hidden = ['image_path', 'image_hash'];

    public function acceptedMethods(): array
    {
        return $this->accepted_methods ?? config('qr_payments.default_methods');
    }

    public function available(): bool
    {
        return $this->enabled && $this->missingFields() === [];
    }

    public function missingFields(): array
    {
        $missing = [];
        if (! $this->image_hash || ! $this->image_path || ! Storage::disk('local')->exists($this->image_path)) $missing[] = 'image';
        if (! trim($this->merchant_name ?? '')) $missing[] = 'merchant_name';
        if ($this->acceptedMethods() === []) $missing[] = 'accepted_methods';
        return $missing;
    }

    public function configuration(bool $admin = false): array
    {
        if (! $admin && ! $this->enabled) return ['enabled' => false];
        $missing = $this->missingFields();
        $available = $this->enabled && $missing === [];
        if (! $admin && ! $available) return ['enabled' => false];

        return [
            'enabled' => $admin ? $this->enabled : $available,
            'version' => $this->version,
            'merchant_name' => $this->merchant_name,
            'accepted_methods' => $this->acceptedMethods(),
            'has_image' => ! in_array('image', $missing, true),
        ] + ($admin ? ['status' => $missing ? 'not_ready' : ($available ? 'enabled' : 'disabled'), 'missing_fields' => $missing] : []);
    }
}
