<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class InventoryExpiryNotificationService
{
    public function __construct(private readonly InventoryBatchBalanceService $batchBalances)
    {
    }

    /** @param list<int>|null $receiptIds Limit post-commit reconciliation to the new receipts. */
    public function reconcile(?array $receiptIds = null): void
    {
        $now = now()->toImmutable();
        $today = $now->startOfDay();
        $cutoff = $this->batchBalances->expiryAlertCutoff($today);
        $receiptSet = $receiptIds === null ? null : array_fill_keys($receiptIds, true);

        InventoryItem::query()
            ->where('is_active', true)
            ->where('quantity_on_hand', '>', 0)
            ->whereHas('batches', function ($query) use ($cutoff, $receiptIds) {
                $query->where('expiry_date', '<=', $cutoff);
                if ($receiptIds !== null) {
                    $query->whereIntegerInRaw('transaction_id', $receiptIds);
                }
            })
            ->chunkById(200, function (Collection $items) use ($now, $today, $cutoff, $receiptSet): void {
                $items = $items->filter(fn (InventoryItem $item) => $this->batchBalances->tracksExpiry($item));
                $balances = $this->batchBalances->forItems($items->modelKeys(), $today);
                $notifications = [];

                foreach ($items as $item) {
                    foreach ($this->batchBalances->expiryAlertBatches($balances[$item->item_id]['batches'], $cutoff) as $batch) {
                        if ($receiptSet !== null && ! isset($receiptSet[$batch['transaction_id']])) {
                            continue;
                        }

                        $notifications[] = [
                            ...$this->snapshot($item, $batch, $today),
                            'inventory_transaction_id' => $batch['transaction_id'],
                            'is_read' => false,
                            'created_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($notifications, 100) as $rows) {
                    // On conflict, retain the original snapshot, timestamp and read state.
                    Notification::query()->upsert($rows, ['inventory_transaction_id', 'type'], ['type']);
                }
            });
    }

    private function snapshot(InventoryItem $item, array $batch, CarbonImmutable $today): array
    {
        $expiry = CarbonImmutable::parse($batch['expiry_date'], $today->timezone);
        $date = $expiry->format($expiry->year === $today->year ? 'M j' : 'M j, Y');
        $days = (int) $today->diffInDays($expiry, false);
        $receipt = filled($batch['batch_number'])
            ? 'Batch '.$batch['batch_number']
            : 'Receipt '.$batch['stock_in_reference'];
        $identity = "{$item->item_name} · {$receipt}";
        $quantity = $batch['remaining_quantity'];
        $unit = Str::plural($item->unit, $quantity == 1 ? 1 : 2);
        $state = $batch['is_expired'] ? 'Expired' : 'Expiring soon';
        $timing = match (true) {
            $batch['is_expired'] => "expired {$date}",
            $days === 0 => 'expires today',
            default => "expires {$date} ({$days} ".Str::plural('day', $days).')',
        };

        return [
            'type' => $batch['is_expired'] ? Notification::TYPE_INVENTORY_EXPIRED : Notification::TYPE_INVENTORY_EXPIRING_SOON,
            'message' => "{$state} — {$identity} {$timing}.",
            'context' => "{$quantity} {$unit} remaining",
        ];
    }
}
