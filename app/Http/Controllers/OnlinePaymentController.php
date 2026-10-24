<?php

namespace App\Http\Controllers;

use App\Models\ClinicAppointment;
use App\Models\GatewayPayment;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\GroomingPaymentReadinessService;
use App\Services\OnlinePaymentService;
use App\Services\PayMongoService;
use App\Services\PosSaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlinePaymentController extends Controller
{
    public function createClinic(Request $request, int $id)
    {
        app(PayMongoService::class)->assertConfigured();
        $attempt = DB::transaction(function () use ($request, $id) {
            $case = ClinicAppointment::whereKey($id)->lockForUpdate()->firstOrFail();
            $active = GatewayPayment::forPayable($case, 'clinic')->unresolved()->first();
            if ($active) return $active;
            abort_if($case->paid, 409, 'This payment has already been completed.');
            abort_unless($case->status === 'for_payment' && ! $case->archived_at && ! $case->hasActiveConfinement(), 422, 'This case is not awaiting payment.');
            $charges = $case->charges()->orderBy('id')->lockForUpdate()->get();
            $total = $charges->sum(fn ($charge) => app(GroomingPaymentReadinessService::class)->moneyToCents($charge->amount));
            if ($total <= 0 || $total > 9999999999) throw ValidationException::withMessages(['payment' => 'Online payment requires a positive total within the supported limit.']);
            $lines = $charges->filter(fn ($charge) => (float) $charge->amount > 0)->map(function ($charge) {
                $money = app(GroomingPaymentReadinessService::class);
                $amount = $money->moneyToCents($charge->amount);
                $unitPrice = $money->moneyToCents($charge->unit_price);
                $quantity = (float) $charge->quantity;
                // PayMongo accepts whole quantities; fractional or rounded lines use the finalized amount.
                $whole = $quantity > 0 && floor($quantity) === $quantity && $unitPrice * (int) $quantity === $amount;
                return ['name' => $charge->description, 'amount' => $whole ? $unitPrice : $amount,
                    'quantity' => $whole ? (int) $quantity : 1, 'currency' => 'PHP'];
            })->values()->all();
            abort_unless(collect($lines)->sum(fn ($line) => $line['amount'] * $line['quantity']) === $total, 422, 'Review the finalized clinic charges.');
            $case->update(['total_amount' => $total / 100]);
            return $this->newAttempt($case, 'clinic', 'final', $request, [
                'total' => number_format($total / 100, 2, '.', ''), 'line_items' => $lines, 'charges' => $charges->toArray(),
            ]);
        }, 3);
        return $this->createdResponse($attempt);
    }

    public function clinicStatus(Request $request, int $id)
    {
        $case = ClinicAppointment::findOrFail($id);
        $attempt = GatewayPayment::forPayable($case, 'clinic')->where('provider', 'paymongo')
            ->when($request->filled('gateway_payment'), fn ($q) => $q->whereKey($request->integer('gateway_payment')))->latest('id')->first();
        if (! $attempt) return response()->json(['success' => true, 'online_payment' => null]);
        app(OnlinePaymentService::class)->refreshStatus($attempt);
        return response()->json($this->response($attempt));
    }

    public function cancelClinic(int $id)
    {
        $case = ClinicAppointment::findOrFail($id);
        $attempt = GatewayPayment::forPayable($case, 'clinic')->where('provider', 'paymongo')->latest('id')->firstOrFail();
        app(OnlinePaymentService::class)->cancel($attempt);
        return response()->json($this->response($attempt));
    }

    public function createPos(Request $request)
    {
        app(PayMongoService::class)->assertConfigured();
        $data = $request->validate([
            'request_key' => 'required|uuid', 'payment_method' => 'required|in:online',
            'items' => 'required|array|min:1|max:100', 'items.*.item_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:99999999',
            'items.*.expected_price' => 'sometimes|numeric|decimal:0,2|min:0|max:999999.99',
            'items.*.expected_stock' => 'sometimes|numeric|decimal:0,2|min:0|max:99999999.99',
            'notes' => 'nullable|string|max:500',
        ]);
        $attempt = DB::transaction(function () use ($request, $data) {
            $actor = User::whereKey($request->user()->user_id)->lockForUpdate()->firstOrFail();
            $repeated = GatewayPayment::where('request_key', $data['request_key'])->first();
            if ($repeated) {
                abort_unless($repeated->context === 'pos' && $repeated->initiated_by === $actor->user_id, 403);
                return $repeated;
            }
            $active = GatewayPayment::forPayable($actor, 'pos')->unresolved()->first();
            if ($active) return $active;
            [$lines, $total] = app(PosSaleService::class)->prepare($data);
            abort_unless($total > 0, 422, 'The sale must have a positive total.');
            $products = collect($lines)->map(fn ($line) => [
                'item_id' => $line['item']->item_id, 'name' => $line['item']->item_name, 'unit' => $line['item']->unit,
                'quantity' => $line['quantity'], 'price_at_sale' => $line['price_at_sale'], 'subtotal' => $line['subtotal'],
            ])->all();
            $attempt = $this->newAttempt($actor, 'pos', $data['payment_method'], $request, [
                'total' => number_format($total, 2, '.', ''), 'products' => $products, 'notes' => $data['notes'] ?? null,
                'line_items' => collect($products)->map(fn ($line) => [
                    'name' => $line['name'], 'amount' => (int) round($line['price_at_sale'] * 100), 'quantity' => $line['quantity'], 'currency' => 'PHP',
                ])->all(),
            ], $data['request_key']);
            DB::table('gateway_stock_reservations')->insert(collect($products)->map(fn ($line) => [
                'gateway_payment_id' => $attempt->id, 'item_id' => $line['item_id'], 'quantity' => $line['quantity'],
            ])->all());
            return $attempt;
        }, 3);
        return $this->createdResponse($attempt);
    }

    public function posStatus(Request $request, ?int $id = null)
    {
        $actor = $request->user();
        $attempt = $id ? GatewayPayment::where('provider', 'paymongo')->where('context', 'pos')->findOrFail($id)
            : GatewayPayment::forPayable($actor, 'pos')->where('provider', 'paymongo')
                ->when($request->filled('request_key'), fn ($q) => $q->where('request_key', $request->input('request_key')),
                    fn ($q) => $q->unresolved())->latest('id')->first();
        if (! $attempt) return response()->json(['success' => true, 'online_payment' => null]);
        abort_unless($attempt->initiated_by === $actor->user_id, 403);
        app(OnlinePaymentService::class)->refreshStatus($attempt);
        return response()->json($this->response($attempt));
    }

    public function cancelPos(Request $request, int $id)
    {
        $attempt = GatewayPayment::where('provider', 'paymongo')->where('context', 'pos')->findOrFail($id);
        abort_unless($attempt->initiated_by === $request->user()->user_id, 403);
        app(OnlinePaymentService::class)->cancel($attempt);
        return response()->json($this->response($attempt));
    }

    private function newAttempt($payable, string $context, string $flow, Request $request, array $snapshot, ?string $key = null): GatewayPayment
    {
        $snapshot['metadata_version'] = 2;
        $snapshot['payment_methods'] = app(PayMongoService::class)->paymentMethods();
        $attempt = GatewayPayment::create([
            'provider' => 'paymongo', 'payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->getKey(),
            'context' => $context, 'flow' => $flow, 'reference' => 'BTH-'.Str::uuid(), 'request_key' => $key,
            'amount' => $snapshot['total'], 'currency' => 'PHP', 'status' => 'pending', 'livemode' => false,
            'initiated_by' => $request->user()->user_id, 'snapshot' => $snapshot, 'reconciliation_reason' => 'checkout_creation_unconfirmed',
        ]);
        app(OnlinePaymentService::class)->returnUrl($attempt, 'success');
        return $attempt;
    }

    private function createdResponse(GatewayPayment $attempt)
    {
        $created = $attempt->wasRecentlyCreated;
        app(OnlinePaymentService::class)->create($attempt);
        $response = $this->response($attempt);
        if ($created && ! $attempt->checkout_url) return response()->json([
            ...$response, 'success' => false, 'message' => 'Unable to create online payment. Check its status before retrying.',
        ], 502);
        return response()->json($response);
    }

    private function response(GatewayPayment $attempt): array
    {
        $response = app(OnlinePaymentService::class)->response($attempt);
        if ($attempt->context === 'pos') {
            $response['notes'] = $attempt->snapshot['notes'] ?? null;
            $response['cart'] = $attempt->snapshot['products'];
            if ($attempt->status === 'paid') $response['receipt'] = PosTransaction::with(['items.item', 'cashier', 'gatewayPayment'])
                ->where('gateway_payment_id', $attempt->id)->first()?->receiptSnapshot();
        }
        if ($attempt->context === 'clinic') {
            $case = ClinicAppointment::with(['charges', 'paidBy'])->findOrFail($attempt->payable_id);
            $response['appointment'] = [
                ...app(AdminClinicController::class)->formatAppointment($case),
                'is_test' => ! $attempt->livemode, 'charges' => in_array($attempt->status, ['pending', 'paid'], true) ? $attempt->snapshot['charges'] : $case->charges->toArray(), 'payment_method_label' => $attempt->methodLabel(),
            ];
        }
        return $response;
    }
}
