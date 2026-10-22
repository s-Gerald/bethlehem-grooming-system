<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\GatewayPayment;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

            $batch = trim($entry['batch_number'] ?? '');
            $unavailable = trim($entry['batch_unavailable_reason'] ?? '');
            $this->validateManufacturerLot($entry, $item->category);

            $attributes = [
                'item_id' => $item->item_id,
                'type' => 'stock_in',
                'quantity' => $entry['quantity'],
                'unit_cost_at_time' => $entry['unit_cost'] ?? $item->unit_cost,
                'reason' => $entry['reason'],
                'batch_number' => $batch !== '' ? $batch : null,
                'batch_unavailable_reason' => $unavailable !== '' ? $unavailable : null,
                'expiry_date' => $entry['expiry_date'] ?? null,
                'notes' => $entry['notes'] ?? null,
                'reference_type' => 'manual',
                'performed_by' => $actor->user_id,
            ];
            $transaction = $this->createReceipt($attributes, $item->item_name);
            $item->increment('quantity_on_hand', $entry['quantity']);

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

    public function validateManufacturerLot(array $entry, string $category, string $keyPrefix = ''): void
    {
        $batch = trim($entry['batch_number'] ?? '');
        $unavailable = trim($entry['batch_unavailable_reason'] ?? '');
        if (in_array($category, ['medicine', 'vaccine'], true)
            && ($batch === '' || preg_match('/^(?:N\/?A|NONE|UNKNOWN|UNAVAILABLE|NOT AVAILABLE|NOT APPLICABLE|-)$/i', $batch))
            && ($batch !== '' || $unavailable === '')) {
            throw ValidationException::withMessages([
                $keyPrefix.'batch_number' => 'Enter the manufacturer lot or explain why it is unavailable.',
            ]);
        }
        if ($batch !== '' && $unavailable !== '') {
            throw ValidationException::withMessages([
                $keyPrefix.'batch_unavailable_reason' => 'Use either a manufacturer lot or an unavailable reason.',
            ]);
        }
    }

    private function createReceipt(array $attributes, string $itemName): InventoryTransaction
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = $this->generateReceiptReference($itemName);
            try {
                $insert = function () use ($attributes, $reference) {
                    $receipt = new InventoryTransaction($attributes);
                    $receipt->stock_in_reference = $reference;
                    $receipt->save();
                    return $receipt;
                };
                // PostgreSQL needs a savepoint after a constraint error. MySQL
                // and SQLite can retry the insert without extra round trips.
                return DB::connection()->getDriverName() === 'pgsql'
                    ? DB::transaction($insert) : $insert();
            } catch (UniqueConstraintViolationException $error) {
                if (! str_contains($error->getMessage(), 'inventory_transactions_stock_in_reference_unique')
                    && ! str_contains($error->getMessage(), 'inventory_transactions.stock_in_reference')) {
                    throw $error;
                }
            }
        }
        abort(503, 'Unable to generate a receipt reference. Please try again.');
    }

    protected function generateReceiptReference(string $itemName): string
    {
        $words = preg_split('/[^A-Z0-9]+/', strtoupper(Str::ascii($itemName)), -1, PREG_SPLIT_NO_EMPTY);
        $meaningful = array_values(array_diff($words, ['THE', 'AND', 'FOR', 'WITH', 'WITHOUT', 'OF', 'IN']));
        $words = $meaningful ?: $words;
        $prefix = count($words) > 1
            ? implode('', array_map(fn ($word) => substr($word, 0, 3), array_slice($words, 0, 4)))
            : ($words[0] ?? 'ITEM');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = '';
        for ($index = 0; $index < 10; $index++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($prefix, 0, 8).'-'.$suffix;
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
                abort(422, "Stock for \"{$item->item_name}\" is held for an unresolved historical payment.");
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
