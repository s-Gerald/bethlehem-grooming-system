<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Payment extends Model
{
    private ?string $resolvedKeyName = null;

    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'booking_id',
        'total_amount',
        'amount_tendered',
        'change_amount',
        'payment_method',
        'payment_status',
        'notes',
        'paid_at',
        'processed_by',
        'payment_provider',
        'provider_method',
        'gateway_payment_id',
        'qr_payment_id',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_amount' => 'decimal:2',
    ];

    /**
     * The active schema uses payment_id while the fresh-install migration uses
     * Laravel's conventional id. Resolve that known drift without depending on
     * either key name in payment workflows.
     */
    public function getKeyName()
    {
        if ($this->resolvedKeyName !== null) {
            return $this->resolvedKeyName;
        }

        // Hydrated models already reveal which schema variant they came from.
        // Avoid an information-schema query every time Eloquent asks for the
        // key while matching eager-loaded payments or serializing a response.
        if (array_key_exists('payment_id', $this->attributes)) {
            return $this->resolvedKeyName = 'payment_id';
        }

        if (array_key_exists('id', $this->attributes)) {
            return $this->resolvedKeyName = 'id';
        }

        // New/query models have no attributes yet, so inspect once for this
        // model instance. An instance-local cache remains correct in tests and
        // deployments that use either supported payments-table definition.
        return $this->resolvedKeyName = Schema::hasColumn($this->getTable(), 'payment_id')
            ? 'payment_id'
            : 'id';
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function scopeRealCollections($query)
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('gateway_payments')
            && \Illuminate\Support\Facades\Schema::hasColumn('payments', 'gateway_payment_id')) {
            $query->whereDoesntHave('gatewayPayment', fn ($gateway) => $gateway->where('livemode', false));
        }
        return $query;
    }

    public function gatewayPayment()
    {
        return $this->belongsTo(GatewayPayment::class);
    }

    public function qrPayment()
    {
        return $this->belongsTo(QrPayment::class);
    }

    public function methodLabel(): string
    {
        if ($this->payment_method === 'qrph') {
            $source = $this->relationLoaded('qrPayment') ? $this->qrPayment?->payment_source : null;
            return $source !== null && trim($source) !== '' ? $source : 'QR Payment';
        }
        return $this->payment_method === 'online' ? 'Online Payment' : GatewayPayment::labelFor($this->payment_method);
    }

    public function products()
    {
        return $this->hasMany(GroomingPaymentProduct::class, 'payment_id', $this->getKeyName());
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by', 'user_id');
    }
}
