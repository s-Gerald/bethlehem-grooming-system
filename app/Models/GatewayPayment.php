<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

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

    public function payable()
    {
        return $this->morphTo();
    }

    public static function forGrooming(Booking $booking)
    {
        return static::forPayable($booking, 'grooming');
    }

    public static function forPayable(Model $payable, string $context)
    {
        return static::query()->where('provider', 'paymongo')->where('context', $context)
            ->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey());
    }

    public static function assertClinicUnlocked(ClinicAppointment $appointment): void
    {
        if (Schema::hasTable('gateway_payments') && static::forPayable($appointment, 'clinic')->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['payment' => 'Cancel the active online payment first.']);
        }
    }

    /** Items must be locked before reserving, consuming or releasing stock. */
    public static function reservedQuantities(iterable $ids): array
    {
        if (! Schema::hasTable('gateway_stock_reservations')) return [];
        return DB::table('gateway_stock_reservations')->whereIn('item_id', collect($ids)->all())
            ->selectRaw('item_id, SUM(quantity) as quantity')->groupBy('item_id')->pluck('quantity', 'item_id')->all();
    }

    public function releaseStock(): void
    {
        if (! Schema::hasTable('gateway_stock_reservations')) return;
        $ids = DB::table('gateway_stock_reservations')->where('gateway_payment_id', $this->id)->pluck('item_id');
        InventoryItem::whereIn('item_id', $ids)->orderBy('item_id')->lockForUpdate()->get();
        DB::table('gateway_stock_reservations')->where('gateway_payment_id', $this->id)->delete();
    }

    public function methodLabel(): string
    {
        return self::labelFor($this->provider_method ?? 'online');
    }

    public static function labelFor(?string $method): string
    {
        return match ($method) {
            'qrph' => 'QR Ph', 'gcash' => 'GCash', 'paymaya', 'maya' => 'Maya',
            'card' => 'Card', 'online' => 'Online Payment', default => ucfirst($method ?? 'cash'),
        };
    }

    /** Call while holding the booking row lock, also used by existing correction actions. */
    public static function assertGroomingUnlocked(Booking $booking): void
    {
        if (Schema::hasTable('gateway_payments') && static::forGrooming($booking)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['payment' => 'Cancel the active online payment first.']);
        }
    }
}
