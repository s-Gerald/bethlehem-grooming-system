<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class InventoryQuantityPolicy
{
    public static function limits(): array
    {
        static $limits;
        return $limits ??= json_decode(file_get_contents(resource_path('data/inventory-policy.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function wholeNumberRules(int $minimum, int $maximum): array
    {
        return ['required', 'integer', 'regex:/^[0-9]+$/D', 'min:'.$minimum, 'max:'.$maximum];
    }

    public static function validateReceivingTotals(iterable $entries): void
    {
        $totals = [];
        foreach ($entries as $index => $entry) {
            $quantity = $entry['quantity'] ?? null;
            $maximum = self::limits()['stock_in_maximum'];
            if (! preg_match('/^[0-9]+$/D', (string) $quantity) || (float) $quantity < 1 || (float) $quantity > $maximum) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "Enter 1–{$maximum} whole units."]);
            }
            $totals[$entry['item_id']] = ($totals[$entry['item_id']] ?? 0) + (int) $quantity;
            if ($totals[$entry['item_id']] > $maximum) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "Maximum per product per submission: {$maximum} units."]);
            }
        }
    }
}
