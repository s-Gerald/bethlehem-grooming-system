<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

/** Historical payment metadata and unresolved holds; no active checkout or settlement. */
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

    public static function forPayable(Model $payable, string $context)
    {
        return static::query()->where('context', $context)
            ->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey());
    }

    public static function assertClinicUnlocked(ClinicAppointment $appointment): void
    {
        if (Schema::hasTable('gateway_payments') && static::forPayable($appointment, 'clinic')->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['payment' => 'An unresolved historical payment requires manual reconciliation before this action.']);
        }
    }

    /** Read-only historical holds. Never release stock based on a local attempt status alone. */
    public static function reservedQuantities(iterable $ids): array
    {
        if (! Schema::hasTable('gateway_stock_reservations')) return [];
        return DB::table('gateway_stock_reservations')->whereIn('item_id', collect($ids)->all())
            ->selectRaw('item_id, SUM(quantity) as quantity')->groupBy('item_id')->pluck('quantity', 'item_id')->all();
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
        if (Schema::hasTable('gateway_payments') && static::forPayable($booking, 'grooming')->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['payment' => 'An unresolved historical payment requires manual reconciliation before this action.']);
        }
    }
}
