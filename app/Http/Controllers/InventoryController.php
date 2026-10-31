<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\GatewayPayment;
use App\Models\InventoryTransaction;
use App\Models\PosTransaction;
use App\Services\InventoryBatchBalanceService;
use App\Services\InventoryStockMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private const MAX_MONEY = 999999.99;

    private const MAX_QUANTITY = 99999999.99;

    public function __construct(
        private readonly InventoryBatchBalanceService $batchBalances,
        private readonly InventoryStockMovementService $stockMovements,
    )
    {
    }

    // ── Auth guard ─────────────────────────────────────────────────────────────

    private function requireAuth(): \App\Models\User
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        return $user;
    }

    // ── Formatters ─────────────────────────────────────────────────────────────

    private function itemsQuery(): Builder
    {
        return InventoryItem::query()->when($this->requireAuth()->isVeterinarian(),
            fn (Builder $query) => $query->whereIn('category', ['medicine', 'vaccine']));
    }

    private function movementsQuery(): Builder
    {
        return InventoryTransaction::query()->when($this->requireAuth()->isVeterinarian(),
            fn (Builder $query) => $query->whereHas('item', fn (Builder $item) => $item->whereIn('category', ['medicine', 'vaccine'])));
    }

    private function formatItem(InventoryItem $item, ?array $balance = null, ?float $reserved = null): array
    {
        $balance ??= $this->batchBalances->forItem((int) $item->item_id);
        $physicalQuantity = (float) $item->quantity_on_hand;
        $reserved ??= (float) (GatewayPayment::reservedQuantities([$item->item_id])[$item->item_id] ?? 0);

        $data = [
            'item_id'          => $item->item_id,
            'item_name'        => $item->item_name,
            'barcode'          => $item->barcode,
            'category'         => $item->category,
            'unit'             => $item->unit,
            'medicine_type' => $item->medicine_type,
            'strength_concentration' => $item->strength_concentration,
            'pricing_method' => $item->pricing_method ?? 'flat',
            'weight_pricing_mode' => $item->weight_pricing_mode ?? 'legacy',
            'weight_prices' => ! $this->requireAuth()->isVeterinarian() && $item->category === 'medicine' && $item->pricing_method === 'weight_based'
                ? $item->weightPrices->map->only(['min_weight_kg', 'max_weight_kg', 'price'])->values()->all() : [],
            'description'      => $item->description,
            'unit_cost'        => $item->unit_cost,
            'selling_price'    => $item->selling_price,
            'quantity_on_hand' => $item->quantity_on_hand,
            'unexpired_quantity' => $balance['unexpired_quantity'],
            'saleable_quantity' => $reserved === 0.0 && in_array($item->category, ['pet_shop', 'miscellaneous'], true)
                ? $item->quantity_on_hand : max(0, (in_array($item->category, ['pet_shop', 'miscellaneous'], true)
                ? $physicalQuantity : min($physicalQuantity, $balance['unexpired_quantity'])) - $reserved),
            'expired_quantity' => $balance['expired_quantity'],
            'expiry_unknown_quantity' => $balance['unknown_expiry_quantity'],
            'historically_unsafe_quantity' => $balance['historically_unsafe_stock_out_quantity'],
            'untracked_quantity' => round(max(
                0,
                $physicalQuantity - (float) $balance['tracked_remaining_quantity'],
            ), 2),
            'reorder_level'    => $item->reorder_level,
            'low_stock'        => $item->low_stock,
            'is_active'        => $item->is_active,
            'created_at'       => $item->created_at,
            'updated_at'       => $item->updated_at,
        ];
        if ($this->requireAuth()->isVeterinarian()) {
            unset($data['unit_cost'], $data['selling_price'], $data['weight_prices'], $data['pricing_method'], $data['weight_pricing_mode']);
        }
        return $data;
    }

    private function formatItems(iterable $items): Collection
    {
        $items = new \Illuminate\Database\Eloquent\Collection($items);
        if (! $this->requireAuth()->isVeterinarian()) {
            $items->where('category', 'medicine')->where('pricing_method', 'weight_based')->loadMissing('weightPrices');
        }
        $balances = $this->batchBalances->forItems($items->pluck('item_id'));
        $reserved = GatewayPayment::reservedQuantities($items->pluck('item_id'));

        return $items->map(fn (InventoryItem $item) => $this->formatItem(
            $item,
            $balances[(int) $item->item_id] ?? $this->batchBalances->emptyBalance(),
            (float) ($reserved[$item->item_id] ?? 0),
        ));
    }

    private function currentExpiryAlerts(): Collection
    {
        $today = now()->startOfDay();
        $cutoff = $today->copy()->addDays(30)->toDateString();
        $items = $this->itemsQuery()->where('is_active', 1)
            ->where('quantity_on_hand', '>', 0)
            ->get();
        $balances = $this->batchBalances->forItems($items->pluck('item_id'));
        $reserved = GatewayPayment::reservedQuantities($items->pluck('item_id'));

        if (! $this->requireAuth()->isVeterinarian()) {
            $items->where('category', 'medicine')->where('pricing_method', 'weight_based')->loadMissing('weightPrices');
        }
        return $items->map(function (InventoryItem $item) use ($balances, $reserved, $today, $cutoff) {
            $balance = $balances[(int) $item->item_id] ?? $this->batchBalances->emptyBalance();
            $expiringBatches = collect($balance['batches'])
                ->filter(fn (array $batch) => $batch['remaining_quantity'] > 0
                    && $batch['expiry_date'] !== null
                    && $batch['expiry_date'] <= $cutoff)
                ->sortBy(fn (array $batch) => [
                    $batch['expiry_date'],
                    $batch['transaction_id'],
                ])
                ->values();

            if ($expiringBatches->isEmpty()) {
                return null;
            }

            $earliest = $expiringBatches->first();
            $expiryDate = CarbonImmutable::parse($earliest['expiry_date']);

            return [
                ...$this->formatItem($item, $balance, (float) ($reserved[$item->item_id] ?? 0)),
                'earliest_expiry' => $earliest['expiry_date'],
                'is_expired' => $earliest['is_expired'],
                'days_until_expiry' => (int) $today->diffInDays($expiryDate, false),
                'expiring_quantity' => round((float) $expiringBatches->sum('remaining_quantity'), 2),
                'expiring_batches' => $expiringBatches->all(),
            ];
        })->filter()->values();
    }

    private function formatTransaction(InventoryTransaction $t): array
    {
        $data = [
            'transaction_id'        => $t->transaction_id,
            'stock_in_reference'    => $t->stock_in_reference,
            'item_id'               => $t->item_id,
            'item_name'             => $t->item?->item_name,
            'category'              => $t->item?->category,
            'unit'                  => $t->item?->unit,
            'type'                  => $t->type,
            'quantity'              => $t->quantity,
            'unit_cost_at_time'     => $t->unit_cost_at_time,
            'selling_price_at_time' => $t->selling_price_at_time,
            'reason'                => $t->reason,
            'batch_number'          => $t->batch_number,
            'batch_unavailable_reason' => $t->batch_unavailable_reason,
            'expiry_date'           => $t->expiry_date?->format('Y-m-d'),
            'reference_type'        => $t->reference_type,
            'reference_id'          => $t->reference_id,
            'source_label'          => $this->requireAuth()->isVeterinarian() && in_array($t->reference_type, ['pos', 'grooming'], true) ? null : match ($t->reference_type) {
                'grooming' => 'Grooming · '.($t->groomingBooking?->booking_reference ?? $t->reference_id),
                'clinic' => $t->reference_id === null
                    ? 'Clinic'
                    : 'Clinic · '.($t->clinicAppointment?->appointment_reference ?? $t->reference_id),
                'pos' => $t->reference_id ? ($t->posTransaction?->reference ?? PosTransaction::referenceFor($t->reference_id)) : 'POS',
                default => null,
            },
            'notes'                 => $t->notes,
            'performed_by'          => $t->performed_by,
            'performed_by_name'     => $t->performedBy
                ? trim("{$t->performedBy->first_name} {$t->performedBy->last_name}")
                : null,
            'created_at'            => $t->created_at,
        ];
        if ($this->requireAuth()->isVeterinarian()) {
            unset($data['unit_cost_at_time'], $data['selling_price_at_time']);
            if (in_array($t->reference_type, ['pos', 'grooming'], true)) {
                $data['source_label'] = null;
                $data['reference_id'] = null;
            }
        }
        return $data;
    }

    private function formatTransactions($transactions): Collection
    {
        // Reference ids are shared by different source tables; hydrate only the
        // matching source so a POS movement never queries grooming/clinic data.
        if (! $this->requireAuth()->isVeterinarian()) {
            $transactions->where('reference_type', 'grooming')->load('groomingBooking');
            $transactions->where('reference_type', 'pos')->load('posTransaction');
        }
        $transactions->where('reference_type', 'clinic')->load('clinicAppointment');
        return $transactions->map(fn ($transaction) => $this->formatTransaction($transaction));
    }

    // ── Product CRUD ───────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->requireAuth();

        $q              = trim($request->query('q', ''));
        $category       = $request->query('category', '');
        $lowStock       = $request->boolean('low_stock');
        $includeInactive = $request->boolean('include_inactive');
        $inactiveOnly   = $request->boolean('inactive_only');
        $page           = max(1, (int) $request->query('page', 1));

        $query = $inactiveOnly
            ? $this->itemsQuery()->where('is_active', 0)
            : ($includeInactive ? $this->itemsQuery() : $this->itemsQuery()->where('is_active', 1));

        if ($q) {
            $query->where(function ($qb) use ($q) {
                $qb->where('item_name', 'like', "%{$q}%")
                   ->orWhere('barcode', 'like', "%{$q}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        if ($lowStock) {
            $query->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level');
        }

        $paginator = $query->orderBy('item_name')->paginate(10, ['*'], 'page', $page);

        return response()->json([
            'data'      => $this->formatItems($paginator->items()),
            'total'     => $paginator->total(),
            'page'      => $page,
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAuth();

        $validated = $this->validateProduct($request);
        $item = DB::transaction(function () use ($validated) {
            $ranges = $validated['weight_prices'];
            unset($validated['weight_prices']);
            $item = InventoryItem::create([...$validated, 'quantity_on_hand' => 0, 'is_active' => 1]);
            $item->weightPrices()->createMany($ranges);
            return $item;
        });

        return response()->json(['data' => $this->formatItem($item)], 201);
    }

    public function show(int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = $this->itemsQuery()->findOrFail($itemId);
        $balance = $this->batchBalances->forItem($itemId);
        $tracksExpiry = ! in_array($item->category, ['pet_shop', 'miscellaneous'], true);
        $cutoff = now()->startOfDay()->addDays(30)->toDateString();
        $batches = collect($balance['batches'])
            ->filter(fn (array $batch) => $batch['remaining_quantity'] > 0)
            ->sortBy(fn (array $batch) => [$batch['expiry_date'] ?? '9999-12-31', $batch['transaction_id']])
            ->map(fn (array $batch) => [
                ...$batch,
                'expiry_status' => ! $tracksExpiry ? 'Not applicable' : ($batch['is_expired'] ? 'Expired'
                    : ($batch['expiry_date'] === null ? 'Expiry unknown'
                        : ($batch['expiry_date'] <= $cutoff ? 'Expiring Soon' : 'Valid'))),
            ])->values()->all();

        return response()->json(['data' => [
            ...$this->formatItem($item, $balance),
            'tracks_expiry' => $tracksExpiry,
            'current_batches' => $batches,
        ]]);
    }

    public function update(Request $request, int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = $this->itemsQuery()->findOrFail($itemId);

        DB::transaction(function () use ($request, $item) {
            $item = InventoryItem::whereKey($item->item_id)->lockForUpdate()->firstOrFail();
            $validated = $this->validateProduct($request, $item);
            $ranges = $validated['weight_prices'];
            unset($validated['weight_prices']);
            $hadWeightPricing = $item->category === 'medicine' && $item->pricing_method === 'weight_based';
            $item->update($validated);
            if ($hadWeightPricing || $ranges) {
                $item->weightPrices()->delete();
                $item->weightPrices()->createMany($ranges);
            }
        });

        return response()->json(['data' => $this->formatItem($item->fresh())]);
    }

    private function validateProduct(Request $request, ?InventoryItem $item = null): array
    {
        $data = $request->all();
        $category = $data['category'] ?? $item?->category;
        $medicine = $category === 'medicine';
        if ($item && $category === $item->category) {
            foreach (['medicine_type', 'strength_concentration', 'pricing_method'] as $field) {
                if (! array_key_exists($field, $data)) $data[$field] = $item->{$field};
            }
            if (! array_key_exists('weight_prices', $data) && ($data['pricing_method'] ?? null) === 'weight_based') {
                $data['weight_prices'] = $item->weightPrices->map->only(['min_weight_kg', 'max_weight_kg', 'price'])->all();
            }
        }
        if (! $medicine) $data['strength_concentration'] = null;
        $weightBased = $medicine && ($data['pricing_method'] ?? null) === 'weight_based';
        $savedRanges = $weightBased && $item
            ? $item->weightPrices->map->only(['min_weight_kg', 'max_weight_kg', 'price'])->all() : [];
        // Partial updates must not reinterpret a schedule that was not edited.
        $preserveSchedule = $weightBased && $item?->pricing_method === 'weight_based'
            && ! $request->exists('weight_prices') && ($data['weight_prices'] ?? []) == $savedRanges;
        $pricingMode = $preserveSchedule ? ($item->weight_pricing_mode ?? 'legacy') : ($data['weight_pricing_mode'] ?? 'threshold');
        $required = $item ? 'sometimes' : 'required';
        $validator = Validator::make($data, [
            'item_name' => $required.'|string|max:150',
            'barcode' => ['nullable', 'string', 'regex:/^[0-9]{1,13}$/',
                \Illuminate\Validation\Rule::unique('inventory_items', 'barcode')->ignore($item?->item_id, 'item_id')],
            'category' => $required.'|in:medicine,vaccine,food,grooming_supply,pet_shop,miscellaneous',
            'unit' => [$required, 'string', 'max:50', function ($attribute, $value, $fail) use ($item) {
                if (InventoryItem::isMeasuredUnit($value) && $value !== $item?->unit) {
                    $fail('Choose a physical inventory unit. Existing legacy units may be preserved without changing their quantities.');
                }
            }],
            'description' => 'nullable|string|max:255',
            'unit_cost' => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_MONEY,
            'selling_price' => ($weightBased ? 'prohibited' : 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_MONEY),
            'reorder_level' => 'required|integer|min:0|max:99999999',
            'medicine_type' => $medicine ? 'required|in:'.implode(',', InventoryItem::MEDICINE_TYPES) : 'prohibited',
            'strength_concentration' => $medicine ? 'nullable|string|max:150' : 'prohibited',
            'pricing_method' => $medicine ? 'required|in:flat,weight_based' : 'nullable|in:flat',
            'weight_pricing_mode' => 'sometimes|in:legacy,threshold,ranges,catalog',
            'weight_final_range' => 'sometimes|in:open,capped',
            'weight_prices' => $weightBased ? 'required|array|min:1|max:100' : 'prohibited',
            'weight_prices.*' => 'required|array:'.($pricingMode === 'catalog' ? 'band,' : '').'min_weight_kg,max_weight_kg,price',
            'weight_prices.*.band' => $pricingMode === 'catalog' ? ($preserveSchedule ? 'sometimes' : 'required').'|in:below,normal,above' : 'prohibited',
            'weight_prices.*.min_weight_kg' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{0,2})?$/', $preserveSchedule || in_array($pricingMode, ['threshold', 'catalog'], true) ? 'min:0' : 'gt:0', 'max:999999.99'],
            'weight_prices.*.max_weight_kg' => ['present', $preserveSchedule || in_array($pricingMode, ['threshold', 'catalog'], true) ? 'nullable' : 'required', 'numeric', 'regex:/^\d+(?:\.\d{0,2})?$/', 'gt:0', 'max:999999.99'],
            'weight_prices.*.price' => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_MONEY,
        ], [
            'weight_prices.*.min_weight_kg.required' => 'Enter a minimum or threshold weight.',
            'weight_prices.*.max_weight_kg.required' => 'Enter a maximum weight for every range.',
            'weight_prices.*.min_weight_kg.numeric' => 'Enter a valid starting weight in kilograms.',
            'weight_prices.*.max_weight_kg.numeric' => 'Enter a valid maximum weight in kilograms.',
            'weight_prices.*.min_weight_kg.regex' => 'Enter starting weights using no more than 2 decimal places.',
            'weight_prices.*.max_weight_kg.regex' => 'Enter maximum weights using no more than 2 decimal places.',
            'weight_prices.*.min_weight_kg.min' => 'Starting weights cannot be negative.',
            'weight_prices.*.min_weight_kg.gt' => 'Starting weight must be greater than 0 kg.',
            'weight_prices.*.max_weight_kg.gt' => 'Maximum weight must be greater than 0 kg.',
            'weight_prices.*.max_weight_kg.present' => 'Enter a maximum weight.',
            'weight_prices.*.price.required' => 'Enter a price for every range.',
            'weight_prices.*.price.numeric' => 'Enter a valid range price.',
            'weight_prices.*.price.decimal' => 'Enter range prices using no more than 2 decimal places.',
            'weight_prices.*.price.min' => 'Range price must be greater than zero.',
        ]);
        $validator->after(function ($validator) use ($data, $weightBased, $preserveSchedule, $pricingMode) {
            if (! $weightBased || $preserveSchedule || $validator->errors()->isNotEmpty()) return;
            $ranges = array_values($data['weight_prices']);
            if ($pricingMode === 'catalog') {
                foreach ($ranges as $index => $range) {
                    if ($range['band'] !== 'above' && $range['max_weight_kg'] === null) {
                        $validator->errors()->add('weight_prices.'.$index.'.max_weight_kg', 'Enter a maximum weight.');
                    }
                }
                if ($validator->errors()->isNotEmpty()) return;
            }
            $error = \App\Support\ProductWeightPricing::configurationError($ranges, $pricingMode);
            if ($error) $validator->errors()->add('weight_prices', $error);
            if ($pricingMode === 'threshold') {
                $final = $ranges[array_key_last($ranges)];
                $open = ($data['weight_final_range'] ?? 'capped') === 'open';
                if (! $open && $final['max_weight_kg'] === null) {
                    $validator->errors()->add('weight_prices', 'Enter a maximum weight or choose And above.');
                } elseif ($open && $final['max_weight_kg'] !== null) {
                    $validator->errors()->add('weight_prices', 'Clear the maximum weight when choosing And above.');
                }
            }
        });
        $validated = $validator->validate();
        unset($validated['weight_final_range']);
        $validated['medicine_type'] = $medicine ? $validated['medicine_type'] : null;
        $validated['strength_concentration'] = $medicine ? ($validated['strength_concentration'] ?? null) : null;
        $validated['pricing_method'] = $medicine ? $validated['pricing_method'] : 'flat';
        $validated['weight_pricing_mode'] = $pricingMode;
        $validated['weight_prices'] = $weightBased
            ? collect($validated['weight_prices'])->map(fn ($range) => [
                'min_weight_kg' => $range['min_weight_kg'], 'max_weight_kg' => $range['max_weight_kg'] ?? null,
                'price' => $range['price'],
            ])->values()->all() : [];
        if ($weightBased) $validated['selling_price'] = null;
        return $validated;
    }

    public function deactivate(int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = $this->itemsQuery()->findOrFail($itemId);

        if ((float) $item->quantity_on_hand > 0) {
            abort(422, "Cannot deactivate \"{$item->item_name}\" — it still has {$item->quantity_on_hand} {$item->unit} in stock. Record a stock-out first.");
        }

        $item->update(['is_active' => 0]);

        return response()->json(['message' => 'Item deactivated.']);
    }

    public function reactivate(int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = $this->itemsQuery()->findOrFail($itemId);
        $item->update(['is_active' => 1]);

        return response()->json(['data' => $this->formatItem($item->fresh())]);
    }

    // ── Barcode & Search ───────────────────────────────────────────────────────

    public function findByBarcode(Request $request, string $barcode): JsonResponse
    {
        $this->requireAuth();

        $item = $this->itemsQuery()->where('barcode', $barcode)
                             ->when($request->query('sale_context') === 'pos', fn ($query) => $query->retailSale())
                             ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', 1))
                             ->first();

        if (! $item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        return response()->json(['data' => $this->formatItem($item)]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->requireAuth();

        $q = trim($request->query('q', ''));
        $retail = in_array($request->query('sale_context'), ['grooming', 'pos'], true);
        $suggestions = $retail && $request->boolean('suggestions') && $q === '';
        $includeInactive = ! $suggestions && $request->boolean('include_inactive');
        if (! $suggestions && mb_strlen($q) < 2) return response()->json(['data' => []]);

        // Discovery includes unavailable retail items; barcode and checkout retain retailSale().
        $query = $this->itemsQuery()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->when($retail, fn ($query) => $query->whereIn('category', InventoryItem::RETAIL_CATEGORIES))
            ->when($suggestions, fn ($query) => $query->retailSale()->where('quantity_on_hand', '>=', 1))
            ->when(! $suggestions, fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('item_name', 'like', "%{$q}%")->orWhere('barcode', 'like', "%{$q}%");
            }));
        if ($suggestions) {
            // Only committed stock-outs count as retail history, including Grooming sales.
            $query->orderByDesc(InventoryTransaction::query()->selectRaw('MAX(transaction_id)')
                ->whereColumn('inventory_transactions.item_id', 'inventory_items.item_id')
                ->where('type', 'stock_out')->where('reason', 'sold'));
        }
        $items = $query
            ->when($retail, fn ($query) => $query->orderByRaw('CASE WHEN is_active = 1 AND quantity_on_hand >= 1 THEN 0 ELSE 1 END'))
            ->orderBy('item_name')->limit($retail ? 60 : 10)->get();
        $formatted = $this->formatItems($items);
        $eligible = fn ($item) => $item['is_active'] && (float) $item['saleable_quantity'] >= 1
            && (float) $item['selling_price'] > 0 && (float) $item['selling_price'] <= self::MAX_MONEY;

        return response()->json([
            'data' => $suggestions ? $formatted->filter($eligible)->take(12)->values()
                : ($retail ? $formatted->sortBy(fn ($item) => [$eligible($item) ? 0 : 1, $item['item_name']])->take(10)->values() : $formatted),
        ]);
    }

    // ── Stock Movements ────────────────────────────────────────────────────────

    public function stockIn(Request $request): JsonResponse
    {
        $user = $this->requireAuth();

        $validated = $request->validate([
            'items'                => 'required|array|min:1',
            'items.*.item_id'      => 'required|integer|exists:inventory_items,item_id',
            'items.*.quantity'     => 'required|integer|min:1|max:99999999',
            'items.*.reason'       => 'required|in:purchase,return,adjustment',
            'items.*.unit_cost'    => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.batch_unavailable_reason' => 'nullable|string|max:255',
            'items.*.stock_in_reference' => 'prohibited',
            'items.*.expiry_date'  => 'nullable',
            'items.*.notes'        => 'nullable|string|max:500',
        ]);

        $categories = $this->itemsQuery()
            ->whereIn('item_id', collect($validated['items'])->pluck('item_id')->unique())
            ->pluck('category', 'item_id');

        foreach ($validated['items'] as $index => $item) {
            $doesNotExpire = in_array($categories[$item['item_id']] ?? null, ['pet_shop', 'miscellaneous'], true);
            $this->stockMovements->validateManufacturerLot($item, $categories[$item['item_id']] ?? '', "items.{$index}.");

            if ($doesNotExpire) {
                $validated['items'][$index]['expiry_date'] = null;
                continue;
            }

            $expiryValidator = Validator::make($item, [
                'expiry_date' => 'required|date|after_or_equal:today',
            ]);

            if ($expiryValidator->fails()) {
                throw ValidationException::withMessages([
                    "items.{$index}.expiry_date" => $expiryValidator->errors()->first('expiry_date'),
                ]);
            }
        }

        $results = DB::transaction(fn () => $this->stockMovements->receive(
            $user,
            $validated['items'],
        ));

        return response()->json(['data' => $results], 201);
    }

    public function stockOut(Request $request): JsonResponse
    {
        $user = $this->requireAuth();

        $validated = $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|integer|exists:inventory_items,item_id',
            'items.*.quantity'       => 'required|integer|min:1|max:99999999',
            'items.*.reason'         => 'required|in:used,sold,expired,damaged,adjustment',
            'items.*.selling_price'  => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'items.*.notes'          => 'nullable|string|max:500',
            'items.*.reference_type' => 'nullable|in:manual,appointment,pos',
            'items.*.reference_id'   => 'nullable|integer',
        ]);

        $categories = $this->itemsQuery()
            ->whereIn('item_id', collect($validated['items'])->pluck('item_id')->unique())
            ->pluck('category', 'item_id');

        foreach ($validated['items'] as $index => $item) {
            if ($item['reason'] === 'expired'
                && in_array($categories[$item['item_id']] ?? null, ['pet_shop', 'miscellaneous'], true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.reason" => 'Expired is not available for Pet Shop or Miscellaneous products.',
                ]);
            }
        }

        $results = DB::transaction(fn () => $this->stockMovements->remove(
            $user,
            $validated['items'],
            enforceBatchAvailability: false,
        ));

        return response()->json(['data' => $results], 201);
    }

    // ── Transaction History ────────────────────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $this->requireAuth();

        $query = $this->movementsQuery()->with(['item', 'performedBy'])
                     ->orderBy('created_at', 'desc');

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->integer('item_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('category')) {
            $query->whereHas('item', fn ($q) => $q->where('category', $request->query('category')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = $request->integer('per_page', 20) === 10 ? 10 : 20;
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'      => $this->formatTransactions($paginator->getCollection()),
            'total'     => $paginator->total(),
            'page'      => $page,
            'last_page' => $paginator->lastPage(),
        ]);
    }

    // ── Alerts ─────────────────────────────────────────────────────────────────

    public function lowStock(Request $request): JsonResponse
    {
        $this->requireAuth();

        $page = max(1, $request->integer('page', 1));
        $paginator = $this->itemsQuery()->where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->orderByRaw('quantity_on_hand / reorder_level ASC')
            ->orderBy('item_id')
            ->paginate(10, ['*'], 'page', $page);

        return response()->json([
            'data' => $this->formatItems($paginator->items()),
            'count' => $paginator->total(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function expiryAlerts(Request $request): JsonResponse
    {
        $this->requireAuth();

        $data = $this->currentExpiryAlerts();
        $page = max(1, $request->integer('page', 1));
        $total = $data->count();

        return response()->json([
            'data' => $data->forPage($page, 10)->values(),
            'count' => $total,
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / 10)),
        ]);
    }

    public function alertBadge(): JsonResponse
    {
        $this->requireAuth();

        $lowStockCount = $this->itemsQuery()->where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->count();

        $expiryCount = $this->currentExpiryAlerts()->count();

        return response()->json([
            'low_stock_count'    => $lowStockCount,
            'expiry_alert_count' => $expiryCount,
            'total'              => $lowStockCount + $expiryCount,
        ]);
    }

    // ── Dashboard Summary ──────────────────────────────────────────────────────

    public function summary(Request $request): JsonResponse
    {
        $this->requireAuth();

        $totalItems    = $this->itemsQuery()->where('is_active', 1)->count();
        $lowStockCount = $this->itemsQuery()->where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->count();

        $expiryAlerts = $this->currentExpiryAlerts();
        $expiryCount = $expiryAlerts->count();

        if ($request->query('view') === 'overview' || $this->requireAuth()->isVeterinarian()) {
            // Reuse the expiry computation above; previews need no pagination
            // counts, batch/price hydration or report-only 30-day aggregates.
            $lowStock = $this->itemsQuery()->where('is_active', 1)
                ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
                ->orderByRaw('quantity_on_hand / reorder_level ASC')->orderBy('item_id')
                ->limit(5)->get(['item_id', 'item_name', 'category', 'unit', 'quantity_on_hand', 'reorder_level']);
            $recent = $this->requireAuth()->isVeterinarian() ? null : $this->movementsQuery()->with(['item', 'performedBy'])
                ->orderBy('created_at', 'desc')->limit(5)->get();

            return response()->json([
                'total_items' => $totalItems,
                'low_stock_count' => $lowStockCount,
                'expiry_alert_count' => $expiryCount,
                'low_stock_items' => $lowStock,
                'expiry_items' => $expiryAlerts->take(5)->map(fn (array $item) => array_intersect_key($item, array_flip([
                    'item_id', 'item_name', 'batch_number', 'earliest_expiry', 'is_expired', 'days_until_expiry',
                ])))->values(),
                ...($recent === null ? [] : ['recent_transactions' => $this->formatTransactions($recent)]),
            ]);
        }

        $recentTransactionsPage = max(1, $request->integer('page', 1));
        $recentTransactions = $this->movementsQuery()->with(['item', 'performedBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(10, ['*'], 'page', $recentTransactionsPage);

        $topUsed = InventoryTransaction::where('inventory_transactions.type', 'stock_out')
            ->join('inventory_items', 'inventory_transactions.item_id', '=', 'inventory_items.item_id')
            ->select(
                'inventory_items.item_id',
                'inventory_items.item_name',
                'inventory_items.unit'
            )
            ->selectRaw('SUM(inventory_transactions.quantity) as total_used')
            ->where('inventory_transactions.created_at', '>=', now()->subDays(30))
            ->groupBy(
                'inventory_items.item_id',
                'inventory_items.item_name',
                'inventory_items.unit'
            )
            ->orderByDesc('total_used')
            ->limit(5)
            ->get();

        return response()->json([
            'total_items'         => $totalItems,
            'low_stock_count'     => $lowStockCount,
            'expiry_alert_count'  => $expiryCount,
            'recent_transactions' => $this->formatTransactions($recentTransactions->getCollection()),
            'recent_transactions_page' => $recentTransactions->currentPage(),
            'recent_transactions_last_page' => $recentTransactions->lastPage(),
            'recent_transactions_total' => $recentTransactions->total(),
            'top_used_30_days'    => $topUsed,
        ]);
    }
}
