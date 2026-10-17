<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentLimitExceededException;
use App\Models\PosTransaction;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\PosSaleService;
use Illuminate\Support\Facades\Schema;
use App\Support\PaymentAmountLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    private const MAX_MONEY = 999999.99;

    // Validate the whole cart without creating a sale or changing stock.
    public function validateCart(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.item_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99999999',
        ], ['items.*.quantity.integer' => 'Quantity must be a whole number.']);
        [, , $snapshots] = app(PosSaleService::class)->cartInventory(collect($validated['items'])->pluck('item_id'));

        return response()->json(['success' => true, 'data' => $snapshots->values()]);
    }

    // POST /api/pos/transactions
    public function processSale(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.item_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99999999',
            'items.*.expected_price' => 'sometimes|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'items.*.expected_stock' => 'sometimes|numeric|decimal:0,2|min:0|max:99999999.99',
            'items.*.price_at_sale' => 'sometimes|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'total_amount' => 'sometimes|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'payment_method' => 'sometimes|in:cash',
            'amount_tendered' => 'required|numeric|decimal:0,2|min:0',
            'change_amount' => 'sometimes|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'notes' => 'nullable|string|max:500',
        ], [
            'amount_tendered.required' => 'Amount paid is required.',
            'items.*.quantity.integer' => 'Quantity must be a whole number.',
        ], ['amount_tendered' => 'amount paid']);

        $pos = DB::transaction(function () use ($request, $validated) {
            $actor = User::whereKey($request->user()->user_id)->lockForUpdate()->firstOrFail();
            if (Schema::hasTable('gateway_payments')
                && GatewayPayment::forPayable($actor, 'pos')->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['payment' => 'Cancel the active online payment first.']);
            }
            [$preparedLines, $computedTotal] = app(PosSaleService::class)->prepare($validated);
            $amountTendered = round((float) $validated['amount_tendered'], 2);
            if ($computedTotal <= 0) {
                abort(422, 'The sale must have a positive total. Review the product quantities.');
            }
            if ($amountTendered + 0.00001 < $computedTotal) {
                throw ValidationException::withMessages(['amount_tendered' => 'Amount paid cannot be less than the final price.']);
            }
            if ($amountTendered > PaymentLimitExceededException::MAX_VALUE) {
                throw new PaymentLimitExceededException('amount paid');
            }
            $maximum = PaymentAmountLimit::maximumFor($computedTotal);
            if ($amountTendered > $maximum) {
                throw ValidationException::withMessages([
                    'amount_tendered' => 'Amount paid cannot exceed ₱'.number_format($maximum, 2).'.',
                ]);
            }

            return app(PosSaleService::class)->settle(
                $actor, $preparedLines, $computedTotal, $amountTendered, $validated['notes'] ?? null,
            );
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Sale processed successfully.',
            'data' => $this->formatTransaction($pos->load(['items.item', 'cashier'])),
        ], 201);
    }

    // GET /api/pos/transactions/{posId}
    public function getReceipt(Request $request, int $posId)
    {
        $pos = PosTransaction::with(['items.item', 'cashier', 'gatewayPayment'])->find($posId);
        if (!$pos) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->formatTransaction($pos)]);
    }

    // GET /api/pos/transactions
    public function getTransactions(Request $request)
    {
        $query = PosTransaction::with(['items.item', 'cashier', 'gatewayPayment']);

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', (int) $request->cashier_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $paginator = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $paginator->getCollection()->map(fn ($pos) => $this->formatTransaction($pos)),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    private function formatTransaction(PosTransaction $pos): array
    {
        return $pos->receiptSnapshot();
    }
}
