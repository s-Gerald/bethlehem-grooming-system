<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Carbon\CarbonInterface;

class InventoryBatchBalanceService
{
    /**
     * Reconstruct the remaining quantity of each received batch. Historical
     * stock-out rows do not identify a batch, so each quantity is allocated in
     * FEFO order with the transaction id as a deterministic tie-breaker. Sales
     * and service usage can consume only batches that were unexpired then;
     * write-offs can consume any physical batch.
     *
     * @param  iterable<int>  $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function forItems(iterable $itemIds, ?CarbonInterface $asOf = null): array
    {
        $ids = collect($itemIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $today = ($asOf ?? now())->toDateString();
        $transactions = InventoryTransaction::query()
            ->whereIn('item_id', $ids)
            ->whereIn('type', ['stock_in', 'stock_out'])
            ->get()
            ->groupBy('item_id');

        $balances = [];

        foreach ($ids as $itemId) {
            $ledger = $transactions->get($itemId, collect())
                ->sort(function (InventoryTransaction $left, InventoryTransaction $right): int {
                    $leftCreatedAt = $left->created_at?->format('Y-m-d H:i:s.u') ?? '';
                    $rightCreatedAt = $right->created_at?->format('Y-m-d H:i:s.u') ?? '';

                    return [$leftCreatedAt, (int) $left->transaction_id]
                        <=> [$rightCreatedAt, (int) $right->transaction_id];
                })
                ->values();

            $batches = [];
            $stockInQuantity = 0.0;
            $stockOutQuantity = 0.0;
            $unallocatedStockOutQuantity = 0.0;
            $historicallyUnsafeStockOutQuantity = 0.0;

            foreach ($ledger as $transaction) {
                $quantity = (float) $transaction->quantity;

                if ($transaction->type === 'stock_in') {
                    $stockInQuantity += $quantity;
                    $batches[] = [
                        'transaction_id' => (int) $transaction->transaction_id,
                        'stock_in_reference' => $transaction->stock_in_reference,
                        'batch_number' => $transaction->batch_number,
                        'batch_unavailable_reason' => $transaction->batch_unavailable_reason,
                        'expiry_date' => $transaction->expiry_date?->format('Y-m-d'),
                        'received_quantity' => $quantity,
                        'remaining_quantity' => $quantity,
                    ];

                    continue;
                }

                $stockOutQuantity += $quantity;
                $quantityToAllocate = $quantity;
                $stockOutDate = $transaction->created_at?->toDateString() ?? $today;
                $requiresUnexpiredStock = in_array($transaction->reason, ['sold', 'used'], true);
                $batchIndexes = array_keys($batches);

                usort($batchIndexes, function (int $leftIndex, int $rightIndex) use ($batches): int {
                    $left = $batches[$leftIndex];
                    $right = $batches[$rightIndex];

                    return [
                        $left['expiry_date'] ?? '9999-12-31',
                        $left['transaction_id'],
                    ] <=> [
                        $right['expiry_date'] ?? '9999-12-31',
                        $right['transaction_id'],
                    ];
                });

                foreach ($batchIndexes as $batchIndex) {
                    if ($quantityToAllocate <= 0) {
                        break;
                    }

                    $expiryDate = $batches[$batchIndex]['expiry_date'];
                    if ($requiresUnexpiredStock
                        && ($expiryDate === null || $expiryDate < $stockOutDate)) {
                        continue;
                    }

                    $consumed = min(
                        (float) $batches[$batchIndex]['remaining_quantity'],
                        $quantityToAllocate,
                    );
                    $batches[$batchIndex]['remaining_quantity'] -= $consumed;
                    $quantityToAllocate -= $consumed;
                }

                // Older data may contain sales/usage recorded after a batch had
                // already expired. The current API blocks that now, but the
                // historical physical decrement still happened. Reconcile any
                // such remainder so exhausted batches do not reappear in alerts.
                if ($requiresUnexpiredStock && $quantityToAllocate > 0) {
                    $unsafeQuantityBeforeFallback = $quantityToAllocate;

                    foreach ($batchIndexes as $batchIndex) {
                        if ($quantityToAllocate <= 0) {
                            break;
                        }

                        $consumed = min(
                            (float) $batches[$batchIndex]['remaining_quantity'],
                            $quantityToAllocate,
                        );
                        $batches[$batchIndex]['remaining_quantity'] -= $consumed;
                        $quantityToAllocate -= $consumed;
                    }

                    $historicallyUnsafeStockOutQuantity += max(
                        0,
                        $unsafeQuantityBeforeFallback - $quantityToAllocate,
                    );
                }

                $unallocatedStockOutQuantity += max(0, $quantityToAllocate);
            }

            $batches = collect($batches)->map(function (array $batch) use ($today): array {
                $expiryDate = $batch['expiry_date'];

                return [
                    ...$batch,
                    'received_quantity' => $this->normalizeQuantity($batch['received_quantity']),
                    'remaining_quantity' => $this->normalizeQuantity($batch['remaining_quantity']),
                    'is_expired' => $expiryDate !== null && $expiryDate < $today,
                    'is_unexpired' => $expiryDate !== null && $expiryDate >= $today,
                ];
            })->values();
            $positiveBatches = $batches
                ->filter(fn (array $batch) => $batch['remaining_quantity'] > 0);

            $balances[$itemId] = [
                'batches' => $batches->all(),
                'total_stock_in_quantity' => $this->normalizeQuantity($stockInQuantity),
                'total_stock_out_quantity' => $this->normalizeQuantity($stockOutQuantity),
                'tracked_remaining_quantity' => $this->normalizeQuantity(
                    $positiveBatches->sum('remaining_quantity'),
                ),
                'unexpired_quantity' => $this->normalizeQuantity(
                    $positiveBatches->where('is_unexpired', true)->sum('remaining_quantity'),
                ),
                'expired_quantity' => $this->normalizeQuantity(
                    $positiveBatches->where('is_expired', true)->sum('remaining_quantity'),
                ),
                'unknown_expiry_quantity' => $this->normalizeQuantity(
                    $positiveBatches->whereNull('expiry_date')->sum('remaining_quantity'),
                ),
                'unallocated_stock_out_quantity' => $this->normalizeQuantity(
                    $unallocatedStockOutQuantity,
                ),
                'historically_unsafe_stock_out_quantity' => $this->normalizeQuantity(
                    $historicallyUnsafeStockOutQuantity,
                ),
            ];
        }

        return $balances;
    }

    /** @return array<string, mixed> */
    public function forItem(int $itemId, ?CarbonInterface $asOf = null): array
    {
        return $this->forItems([$itemId], $asOf)[$itemId] ?? $this->emptyBalance();
    }

    /** Availability for clinical use, independent of dosage and pricing. */
    public function treatmentAvailability(InventoryItem $item, array $balance): array
    {
        $physical = (float) $item->quantity_on_hand;
        $available = max(0, min($physical, (float) $balance['unexpired_quantity']));
        $legacyUnit = InventoryItem::isMeasuredUnit($item->unit);
        $status = match (true) {
            $physical <= 0 => 'out_of_stock',
            $available <= 0 && $balance['expired_quantity'] > 0 => 'expired',
            $legacyUnit => 'legacy_unit',
            $available < 1 => 'unavailable',
            $available <= (float) $item->reorder_level => 'low_stock',
            default => 'available',
        };
        $batch = $this->nextAvailableBatch($balance);

        return [
            'is_active' => $item->is_active,
            'quantity_on_hand' => $physical,
            'available_quantity' => $legacyUnit ? 0 : $available,
            'stock_status' => $status,
            'next_batch' => $batch ? ['batch_number' => $batch['batch_number'], 'expiry_date' => $batch['expiry_date']] : null,
        ];
    }

    /**
     * The batch the next sale/usage stock-out would be allocated to (FEFO).
     *
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed>|null
     */
    public function nextAvailableBatch(array $balance): ?array
    {
        return collect($balance['batches'] ?? [])
            ->filter(fn (array $batch) => $batch['remaining_quantity'] > 0 && $batch['is_unexpired'])
            ->sortBy(fn (array $batch) => [$batch['expiry_date'], $batch['transaction_id']])
            ->first();
    }

    /** @return array<string, mixed> */
    public function emptyBalance(): array
    {
        return [
            'batches' => [],
            'total_stock_in_quantity' => 0.0,
            'total_stock_out_quantity' => 0.0,
            'tracked_remaining_quantity' => 0.0,
            'unexpired_quantity' => 0.0,
            'expired_quantity' => 0.0,
            'unknown_expiry_quantity' => 0.0,
            'unallocated_stock_out_quantity' => 0.0,
            'historically_unsafe_stock_out_quantity' => 0.0,
        ];
    }

    private function normalizeQuantity(float $quantity): float
    {
        return round(max(0, $quantity), 2);
    }
}
