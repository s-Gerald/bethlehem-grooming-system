<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTransaction extends Model
{
    protected $table      = 'pos_transactions';
    protected $primaryKey = 'pos_id';

    // Only created_at — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'cashier_id',
        'total_amount',
        'amount_tendered',
        'change_amount',
        'notes',
        'payment_method',
        'gateway_payment_id',
    ];

    protected $casts = [
        'total_amount'    => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_amount'   => 'decimal:2',
        'created_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = now();
        });
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id', 'user_id');
    }

    public function gatewayPayment()
    {
        return $this->belongsTo(GatewayPayment::class);
    }

    public function items()
    {
        return $this->hasMany(PosTransactionItem::class, 'pos_id', 'pos_id');
    }

    public static function referenceFor(int $id): string
    {
        return 'POS-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function receiptSnapshot(): array
    {
        return [
            'pos_id' => $this->pos_id,
            'reference' => self::referenceFor($this->pos_id),
            'transaction_type' => 'product_sale',
            'payment_method' => $this->payment_method ?? 'cash',
            'payment_method_label' => $this->gatewayPayment?->methodLabel() ?? GatewayPayment::labelFor($this->payment_method ?? 'cash'),
            'is_test' => $this->gatewayPayment ? ! $this->gatewayPayment->livemode : false,
            'cashier_id' => $this->cashier_id,
            'cashier_name' => $this->cashier
                ? trim($this->cashier->first_name.' '.$this->cashier->last_name) : null,
            'total_amount' => $this->total_amount,
            'amount_tendered' => $this->amount_tendered,
            'change_amount' => $this->change_amount,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item_name' => $line->item_name ?? $line->item?->item_name,
                'unit' => $line->unit ?? $line->item?->unit,
                'quantity' => $line->quantity,
                'price_at_sale' => $line->price_at_sale,
                'subtotal' => $line->subtotal,
            ])->values()->all(),
        ];
    }
}
