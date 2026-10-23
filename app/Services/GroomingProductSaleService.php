<?php

namespace App\Services;

use App\Models\GroomingPaymentProduct;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class GroomingProductSaleService
{
    public function __construct(
        private readonly InventoryBatchBalanceService $balances,
        private readonly InventoryStockMovementService $movements,
    ) {}

    /** Validate current stock and prices while the caller's payment transaction is open. */
    public function prepare(array $requested): array
    {
        if ($requested === []) {
            return [[], 0];
        }

        $lines = [];
        $totalCents = 0;
        $requestedLines = collect($requested)->sortBy('item_id')->values();
        $items = InventoryItem::query()
            ->whereIn('item_id', $requestedLines->pluck('item_id'))
            ->orderBy('item_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('item_id');
        $expiringIds = $items->filter(fn ($item) => ! in_array($item->category, ['pet_shop', 'miscellaneous'], true))
            ->keys();
        $balances = $this->balances->forItems($expiringIds);

        $reserved = \App\Models\GatewayPayment::reservedQuantities($items->keys());
        foreach ($requestedLines as $requestedLine) {
            $item = $items->get($requestedLine['item_id']);
            if (! $item || ! $item->is_active || in_array($item->category, ['medicine', 'vaccine'], true)
                || $item->selling_price === null) {
                throw ValidationException::withMessages([
                    'products' => 'One or more products are no longer available for sale.',
                ]);
            }

            $quantity = (int) $requestedLine['quantity'];
            $available = (float) $item->quantity_on_hand;
            if (! in_array($item->category, ['pet_shop', 'miscellaneous'], true)) {
                $available = min($available,
                    (float) $balances[$item->item_id]['unexpired_quantity']);
            }
            $available = max(0, $available - ($reserved[$item->item_id] ?? 0));
            if ($available < $quantity) {
                throw ValidationException::withMessages([
                    'products' => "{$item->item_name} no longer has enough stock. Available: {$available} {$item->unit}.",
                ]);
            }

            $priceCents = (int) round((float) $item->selling_price * 100);
            $subtotalCents = $priceCents * $quantity;
            $totalCents += $subtotalCents;
            $lines[] = compact('item', 'quantity', 'priceCents', 'subtotalCents');
        }

        return [$lines, $totalCents];
    }

    public function record(Payment $payment, User $actor, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $entries = [];
        foreach ($lines as $line) {
            $item = $line['item'];
            GroomingPaymentProduct::create([
                'payment_id' => $payment->getKey(),
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'quantity' => $line['quantity'],
                'price_at_sale' => number_format($line['priceCents'] / 100, 2, '.', ''),
                'subtotal' => number_format($line['subtotalCents'] / 100, 2, '.', ''),
            ]);
            $entries[] = [
                'item_id' => $item->item_id,
                'quantity' => $line['quantity'],
                'unit_cost_at_time' => $item->unit_cost,
                'selling_price' => number_format($line['priceCents'] / 100, 2, '.', ''),
                'reason' => 'sold',
                'reference_type' => 'grooming',
                'reference_id' => $payment->booking_id,
            ];
        }
        $this->movements->remove($actor, $entries);
    }

    /** Recheck stock under lock while retaining the exact charged prices and names. */
    public function prepareFrozen(array $frozen): array
    {
        [$lines] = $this->prepare($frozen);
        $snapshots = collect($frozen)->keyBy('item_id');
        foreach ($lines as &$line) {
            $snapshot = $snapshots->get($line['item']->item_id);
            $line['priceCents'] = $snapshot['priceCents'];
            $line['subtotalCents'] = $snapshot['subtotalCents'];
            $line['item']->item_name = $snapshot['name'];
        }
        return $lines;
    }

}
