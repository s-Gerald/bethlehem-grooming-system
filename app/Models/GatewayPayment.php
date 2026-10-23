<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

/** Hosted checkout attempts, historical metadata and unresolved financial holds. */
class GatewayPayment extends Model
{
    protected $fillable = [
        'provider', 'payable_type', 'payable_id', 'context', 'flow', 'reference', 'request_key',
        'amount', 'currency', 'status', 'livemode', 'checkout_session_id', 'provider_payment_id',
        'provider_event_id', 'checkout_url', 'provider_method', 'snapshot', 'initiated_by',
        'paid_at', 'reconciliation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'snapshot' => 'array',
        'livemode' => 'boolean', 'paid_at' => 'datetime',
    ];

    public function scopeUnresolved($query)
    {
        return $query->where(fn ($q) => $q->where('status', 'pending')
            ->orWhere(fn ($q) => $q->where('status', 'paid')->whereNotNull('reconciliation_reason')));
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public static function forGrooming(Booking $booking)
    {
        return static::forPayable($booking, 'grooming')->where('provider', 'paymongo');
    }

    public static function forPayable(Model $payable, string $context)
    {
        return static::query()->where('context', $context)
            ->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey());
    }

    public static function assertClinicUnlocked(ClinicAppointment $appointment): void
    {
        if (Schema::hasTable('gateway_payments') && static::forPayable($appointment, 'clinic')->unresolved()->exists()) {
            throw ValidationException::withMessages(['payment' => 'Cancel the active online payment first.']);
        }
    }

    /** Never release stock based on a local timeout or browser redirect. */
    public static function reservedQuantities(iterable $ids): array
    {
        if (! Schema::hasTable('gateway_stock_reservations')) return [];
        return DB::table('gateway_stock_reservations')->whereIn('item_id', collect($ids)->all())
            ->selectRaw('item_id, SUM(quantity) as quantity')->groupBy('item_id')->pluck('quantity', 'item_id')->all();
    }

    public static function labelFor(?string $method): string
    {
        return match ($method) {
            'qrph' => 'QR Payment', 'gcash' => 'GCash', 'paymaya', 'maya' => 'Maya',
            'card' => 'Card', 'online' => 'Online Payment', default => ucfirst($method ?? 'cash'),
        };
    }

    /** Caller holds the payable and attempt locks, within a database transaction. */
    public function releaseStock(): void
    {
        if (! Schema::hasTable('gateway_stock_reservations')) return;
        $ids = DB::table('gateway_stock_reservations')->where('gateway_payment_id', $this->id)->pluck('item_id');
        InventoryItem::whereIn('item_id', $ids)->orderBy('item_id')->lockForUpdate()->get();
        DB::table('gateway_stock_reservations')->where('gateway_payment_id', $this->id)->delete();
    }

    public function methodLabel(): string
    {
        $method = $this->provider_method;
        return $method ? 'Online Payment · '.($method === 'qrph' ? 'QR Ph' : self::labelFor($method)) : 'Online Payment';
    }

    /** Call while holding the booking row lock, also used by existing correction actions. */
    public static function assertGroomingUnlocked(Booking $booking): void
    {
        if (Schema::hasTable('gateway_payments') && static::forPayable($booking, 'grooming')->unresolved()->exists()) {
            throw ValidationException::withMessages(['payment' => 'Cancel the active online payment first.']);
        }
    }
}
