<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $table      = 'inventory_transactions';
    protected $primaryKey = 'transaction_id';

    // Only created_at — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'type',
        'quantity',
        'unit_cost_at_time',
        'selling_price_at_time',
        'reason',
        'batch_number',
        'batch_unavailable_reason',
        'expiry_date',
        'reference_type',
        'reference_id',
        'notes',
        'performed_by',
        'vaccination_record_id',
    ];

    protected $casts = [
        'quantity'              => 'decimal:2',
        'unit_cost_at_time'     => 'decimal:2',
        'selling_price_at_time' => 'decimal:2',
        'expiry_date'           => 'date',
        'created_at'            => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = now();
        });
        static::updating(function (self $model) {
            if ($model->isDirty('stock_in_reference')) {
                throw new \LogicException('Stock receipt references cannot be changed.');
            }
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    // Persisted references belong to the receipt, independent of product renames.
    // Historical SI references remain unchanged without rewriting the ledger.
    public function getStockInReferenceAttribute(): ?string
    {
        if ($this->type !== 'stock_in') {
            return null;
        }

        return $this->attributes['stock_in_reference']
            ?? sprintf('SI-%s-%05d', $this->created_at?->format('Y') ?? '0000', $this->transaction_id);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id', 'item_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }

    public function groomingBooking()
    {
        return $this->belongsTo(Booking::class, 'reference_id', 'booking_id');
    }

    public function clinicAppointment()
    {
        return $this->belongsTo(ClinicAppointment::class, 'reference_id', 'id');
    }

    public function posTransaction()
    {
        return $this->belongsTo(PosTransaction::class, 'reference_id', 'pos_id');
    }
}
