<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\User;

class PosSaleService
{
    private const MAX_MONEY = 999999.99;

    public function __construct(
        private readonly InventoryStockMovementService $stockMovements,
        private readonly InventoryBatchBalanceService $batchBalances,
    ) {}

    public function cartInventory($ids, bool $lock = false): array
    {
        $ids = $ids->unique()->sort()->values();
        $items = InventoryItem::whereIn('item_id', $ids)->orderBy('item_id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('item_id');
        $balances = $this->batchBalances->forItems($items
            ->reject(fn ($item) => in_array($item->category, ['pet_shop', 'miscellaneous'], true))->keys());
        $reserved = \App\Models\GatewayPayment::reservedQuantities($ids);
        $snapshots = $ids->mapWithKeys(function ($id) use ($items, $balances, $reserved) {
            $item = $items->get($id);
            $physical = (float) ($item?->quantity_on_hand ?? 0);

            return [$id => [
                'item_id' => (int) $id,
                'item_name' => $item?->item_name,
                'unit' => $item?->unit,
                'category' => $item?->category,
                'is_active' => (bool) ($item?->is_active ?? false),
                'selling_price' => $item?->selling_price,
                'saleable_quantity' => isset($balances[$id])
                    ? max(0, min($physical, (float) $balances[$id]['unexpired_quantity']) - ($reserved[$id] ?? 0)) : max(0, $physical - ($reserved[$id] ?? 0)),
            ]];
        });

        return [$items, $balances, $snapshots];
    }

    public function prepare(array $validated): array
    {
        $requestedLines = collect($validated['items'])
            ->groupBy(fn (array $line) => (int) $line['item_id'])
            ->map(fn ($lines, int $itemId) => [
                'item_id' => $itemId,
                'quantity' => (int) $lines->sum('quantity'),
                'expected_prices' => $lines->pluck('expected_price')->filter(fn ($price) => $price !== null)->all(),
                'expected_stocks' => $lines->pluck('expected_stock')->filter(fn ($stock) => $stock !== null)->all(),
            ])
            ->sortBy('item_id')
            ->values();
        $preparedLines = [];
        $computedTotal = 0.0;
        [$items, $balances, $snapshots] = $this->cartInventory($requestedLines->pluck('item_id'), true);
        // Recheck the reviewed snapshot under the same locks used for sale writes.
        // Changes after preflight must also return the cart for staff review.
        foreach ($requestedLines as $line) {
            $current = $snapshots->get($line['item_id']);
            $hasSnapshot = $line['expected_prices'] !== [] || $line['expected_stocks'] !== [];
            $unavailable = ! $current['is_active']
                || ! in_array($current['category'], InventoryItem::RETAIL_CATEGORIES, true)
                || (float) $current['selling_price'] <= 0 || (float) $current['selling_price'] > self::MAX_MONEY;
            $priceChanged = collect($line['expected_prices'])->contains(fn ($price) =>
                (int) round((float) $price * 100) !== (int) round((float) $current['selling_price'] * 100));
            $stockChanged = collect($line['expected_stocks'])->contains(fn ($stock) =>
                (int) round((float) $stock * 100) !== (int) round($current['saleable_quantity'] * 100));
            if (($hasSnapshot && $unavailable) || $priceChanged || $stockChanged) {
                abort(response()->json([
                    'success' => false, 'code' => 'POS_CART_CHANGED',
                    'message' => 'Products changed. Review the updated cart before completing the sale again.',
                    'data' => $snapshots->values(),
                ], 422));
            }
        }

        foreach ($requestedLines as $line) {
            $item = $items->get($line['item_id']);

            if (! $item || ! $item->is_active) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Item ID '.$line['item_id'].' is inactive or not found.',
                ], 422));
            }

            if (! in_array($item->category, InventoryItem::RETAIL_CATEGORIES, true)) {
                abort(response()->json([
                    'success' => false,
                    'message' => '"'.$item->item_name.'" cannot be sold through Point of Sale. Medicine and Vaccine are excluded.',
                ], 422));
            }

            if ($item->selling_price === null || (float) $item->selling_price <= 0) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'A positive selling price must be set for "'.$item->item_name.'" before it can be sold.',
                ], 422));
            }

            if ((float) $item->quantity_on_hand < $line['quantity']) {
                abort(422, "Insufficient stock for \"{$item->item_name}\". Available: {$item->quantity_on_hand} {$item->unit}.");
            }
            if (isset($balances[$item->item_id])
                && (float) $balances[$item->item_id]['unexpired_quantity'] + 0.00001 < $line['quantity']) {
                $available = $balances[$item->item_id]['unexpired_quantity'];
                abort(422, "Insufficient unexpired stock for \"{$item->item_name}\". Unexpired available: {$available} {$item->unit}; physical stock: {$item->quantity_on_hand} {$item->unit}.");
            }

            if ($snapshots->get($item->item_id)['saleable_quantity'] < $line['quantity']) {
                abort(422, 'Stock is reserved for an unresolved payment.');
            }

            $priceAtSale = round((float) $item->selling_price, 2);
            $subtotal = round((float) $line['quantity'] * $priceAtSale, 2);
            if ($priceAtSale > self::MAX_MONEY || $subtotal > self::MAX_MONEY || $line['quantity'] > 99999999) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'The sale exceeds the maximum supported transaction amount of ₱'.number_format(self::MAX_MONEY, 2).'.',
                ], 422));
            }
            $computedTotal = round($computedTotal + $subtotal, 2);
            if ($computedTotal > self::MAX_MONEY) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'The sale exceeds the maximum supported transaction amount of ₱'.number_format(self::MAX_MONEY, 2).'.',
                ], 422));
            }
            $preparedLines[] = [
                'item' => $item,
                'quantity' => $line['quantity'],
                'price_at_sale' => $priceAtSale,
                'subtotal' => $subtotal,
            ];
        }

        return [$preparedLines, $computedTotal];
    }

    public function settle(User $actor, array $preparedLines, float $computedTotal, float $amountTendered, ?string $notes, ?\App\Models\GatewayPayment $gateway = null): PosTransaction
    {
        $pos = PosTransaction::create([
            'cashier_id' => $actor->user_id,
            'total_amount' => $computedTotal,
            'amount_tendered' => $amountTendered,
            'change_amount' => ((int) round($amountTendered * 100) - (int) round($computedTotal * 100)) / 100,
            'notes' => $notes,
            ...($gateway ? ['payment_method' => 'online', 'gateway_payment_id' => $gateway->id] : []),
        ]);

        $stockOutEntries = [];

        foreach ($preparedLines as $line) {
            /** @var InventoryItem $item */
            $item = $line['item'];
            PosTransactionItem::create([
                'pos_id' => $pos->pos_id,
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'quantity' => $line['quantity'],
                'price_at_sale' => $line['price_at_sale'],
                'subtotal' => $line['subtotal'],
            ]);

            $stockOutEntries[] = [
                'item_id' => $item->item_id,
                'quantity' => $line['quantity'],
                'unit_cost_at_time' => $item->unit_cost,
                'selling_price' => $line['price_at_sale'],
                'reason' => 'sold',
                'reference_type' => 'pos',
                'reference_id' => $pos->pos_id,
            ];
        }

        $this->stockMovements->remove($actor, $stockOutEntries);

        return $pos;
    }
}
