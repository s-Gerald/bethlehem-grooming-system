<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\GatewayPayment;
use App\Models\InventoryTransaction;
use App\Models\User;

class InventoryStockMovementService
{
    private const MAX_QUANTITY = 99999999.99;

    public function __construct(private readonly InventoryBatchBalanceService $batchBalances)
    {
    }

    /**
     * Records validated stock receipts inside the caller's database transaction.
     *
     * @param iterable<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    public function receive(User $actor, iterable $entries): array
    {
        $results = [];

        $entries = collect($entries)
            ->sortBy(fn (array $entry) => (int) $entry['item_id'])
            ->values();

        foreach ($entries as $entry) {
            $item = InventoryItem::where('item_id', $entry['item_id'])
                ->where('is_active', 1)
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $item->quantity_on_hand + (float) $entry['quantity'] > self::MAX_QUANTITY) {
                abort(422, "Stock-in would exceed the maximum supported quantity for \"{$item->item_name}\".");
            }

            $item->increment('quantity_on_hand', $entry['quantity']);

            $transaction = InventoryTransaction::create([
                'item_id' => $item->item_id,
                'type' => 'stock_in',
                'quantity' => $entry['quantity'],
                'unit_cost_at_time' => $entry['unit_cost'] ?? $item->unit_cost,
                'reason' => $entry['reason'],
                'batch_number' => $entry['batch_number'] ?? null,
                'expiry_date' => $entry['expiry_date'] ?? null,
                'notes' => $entry['notes'] ?? null,
                'reference_type' => 'manual',
                'performed_by' => $actor->user_id,
            ]);

            $results[] = [
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'quantity_added' => $entry['quantity'],
                'stock_in_reference' => $transaction->stock_in_reference,
                'quantity_on_hand' => $item->quantity_on_hand,
                'unexpired_quantity' => $this->batchBalances
                    ->forItem((int) $item->item_id)['unexpired_quantity'],
                'transaction_id' => $transaction->transaction_id,
            ];
        }

        return $results;
    }

    /**
     * Records validated stock removals inside the caller's database transaction.
     *
     * @param iterable<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    public function remove(User $actor, iterable $entries, bool $enforceBatchAvailability = true): array
    {
        $results = [];
        $reasonPriority = fn (string $reason): int => match ($reason) {
            'expired' => 0,
            'sold', 'used' => 1,
            default => 2,
        };

        $entries = collect($entries)
            ->sort(function (array $left, array $right) use ($reasonPriority): int {
                return [
                    (int) $left['item_id'],
                    $reasonPriority($left['reason']),
                ] <=> [
                    (int) $right['item_id'],
                    $reasonPriority($right['reason']),
                ];
            })
            ->values();

        $items = InventoryItem::whereIn('item_id', $entries->pluck('item_id')->unique())
            ->where('is_active', 1)->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
        $reservations = GatewayPayment::reservedQuantities($items->keys());
        foreach ($entries as $entry) {
            $item = $items->get($entry['item_id']);
            abort_unless($item, 404);
            $reserved = $reservations[$item->item_id] ?? 0;
            if ($reserved > 0 && (float) $item->quantity_on_hand - $reserved < (float) $entry['quantity']) {
                abort(422, "Stock for \"{$item->item_name}\" is reserved for an online payment.");
            }

            if ((float) $item->quantity_on_hand < (float) $entry['quantity']) {
                abort(422, "Insufficient stock for \"{$item->item_name}\". Available: {$item->quantity_on_hand} {$item->unit}.");
            }

            if ($enforceBatchAvailability && in_array($entry['reason'], ['sold', 'used'], true)
                && ! in_array($item->category, ['pet_shop', 'miscellaneous'], true)) {
                $balance = $this->batchBalances->forItem((int) $item->item_id);

                if ((float) $balance['unexpired_quantity'] - $reserved + 0.00001 < (float) $entry['quantity']) {
                    abort(422, "Insufficient unexpired stock for \"{$item->item_name}\". Unexpired available: {$balance['unexpired_quantity']} {$item->unit}; physical stock: {$item->quantity_on_hand} {$item->unit}.");
                }
            }
            if ($enforceBatchAvailability && $entry['reason'] === 'expired') {
                $balance = $this->batchBalances->forItem((int) $item->item_id);

                if ((float) $balance['expired_quantity'] + 0.00001 < (float) $entry['quantity']) {
                    abort(422, "Insufficient expired stock for \"{$item->item_name}\". Expired available: {$balance['expired_quantity']} {$item->unit}; physical stock: {$item->quantity_on_hand} {$item->unit}.");
                }
            }

            $item->decrement('quantity_on_hand', $entry['quantity']);

            $transaction = InventoryTransaction::create([
                'item_id' => $item->item_id,
                'type' => 'stock_out',
                'quantity' => $entry['quantity'],
                'unit_cost_at_time' => $entry['unit_cost_at_time'] ?? null,
                'selling_price_at_time' => $entry['selling_price'] ?? $item->selling_price,
                'reason' => $entry['reason'],
                'batch_number' => $entry['batch_number'] ?? null,
                'expiry_date' => $entry['expiry_date'] ?? null,
                'notes' => $entry['notes'] ?? null,
                'reference_type' => $entry['reference_type'] ?? 'manual',
                'reference_id' => $entry['reference_id'] ?? null,
                'performed_by' => $actor->user_id,
                ...(! empty($entry['vaccination_record_id']) ? ['vaccination_record_id' => $entry['vaccination_record_id']] : []),
            ]);

            $results[] = [
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'quantity_removed' => $entry['quantity'],
                'quantity_on_hand' => $item->quantity_on_hand,
                'unexpired_quantity' => $this->batchBalances
                    ->forItem((int) $item->item_id)['unexpired_quantity'],
                'transaction_id' => $transaction->transaction_id,
            ];
        }

        return $results;
    }
}
