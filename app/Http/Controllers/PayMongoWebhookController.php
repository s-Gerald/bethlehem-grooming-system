<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\GroomingPaymentReadinessService;
use App\Services\GroomingPaymentSettlementService;
use App\Services\PayMongoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, PayMongoService $provider)
    {
        abort_unless($provider->validSignature($request->getContent(), (string) $request->header('Paymongo-Signature')), 401, 'Invalid webhook signature.');
        $payload = json_decode($request->getContent(), true);
        abort_unless(is_array($payload), 400, 'Invalid webhook payload.');
        // PayMongo's event resource envelope and the current send.webhook envelope.
        $event = data_get($payload, 'data.attributes', data_get($payload, 'data', []));
        if (($event['type'] ?? null) !== 'checkout_session.payment.paid') return response()->json(['success' => true]);
        abort_unless(($event['livemode'] ?? null) === $provider->livemode(), 422, 'Webhook environment mismatch.');
        $session = $event['data'] ?? [];
        $sessionId = $session['id'] ?? null;
        abort_unless(is_string($sessionId), 422, 'Checkout Session is missing.');
        $existing = GatewayPayment::where('provider', 'paymongo')->where('checkout_session_id', $sessionId)->first();
        $model = match ($existing?->context) {
            'grooming' => Booking::class, 'clinic' => \App\Models\ClinicAppointment::class, 'pos' => User::class, default => null,
        };
        abort_unless($model && $existing->payable_type === (new $model)->getMorphClass(), 422, 'Unknown online payment.');

        return DB::transaction(function () use ($payload, $event, $session, $sessionId, $existing, $provider, $model) {
            $payable = $model::whereKey($existing->payable_id)->lockForUpdate()->firstOrFail();
            $attempt = GatewayPayment::whereKey($existing->id)->lockForUpdate()->firstOrFail();
            $payments = collect(data_get($session, 'attributes.payments', []))
                ->filter(fn ($payment) => data_get($payment, 'attributes.status') === 'paid')->values();
            abort_unless($payments->count() === 1, 422, 'A paid payment is required.');
            $payment = $payments->first();
            $attributes = $payment['attributes'];
            $money = app(GroomingPaymentReadinessService::class);
            abort_unless($attempt->checkout_session_id === $sessionId
                && $attempt->livemode === $provider->livemode()
                && data_get($session, 'attributes.livemode', $attempt->livemode) === $attempt->livemode
                && ($attributes['livemode'] ?? $attempt->livemode) === $attempt->livemode
                && $attempt->currency === 'PHP' && ($attributes['currency'] ?? null) === 'PHP'
                && is_int($attributes['amount'] ?? null)
                && $attributes['amount'] === $money->moneyToCents($attempt->amount)
                && $money->moneyToCents($attempt->snapshot['total']) === $attributes['amount']
                && ! empty($payment['id'])
                && (string) data_get($session, 'attributes.metadata.gateway_payment_id') === (string) $attempt->id
                && collect(app(\App\Services\OnlinePaymentService::class)->metadata($attempt))->every(fn ($value, $key) =>
                    ($key === 'context' && $attempt->context === 'grooming' && data_get($session, 'attributes.metadata.context') === null)
                    || (string) data_get($session, 'attributes.metadata.'.$key) === $value),
                422, 'Online payment details do not match.');
            if ($attempt->status === 'paid') {
                abort_unless($attempt->provider_payment_id === $payment['id'], 422, 'Payment identifier mismatch.');
                return response()->json(['success' => true]);
            }
            abort_unless(in_array(data_get($attributes, 'source.type'), ['qrph', 'gcash', 'paymaya', 'maya', 'card'], true), 422, 'Unknown payment method.');
            $eventId = data_get($payload, 'data.type') === 'event' ? data_get($payload, 'data.id')
                : ($payload['id'] ?? $payload['event_id'] ?? $event['id'] ?? null);
            abort_if(GatewayPayment::where('provider_payment_id', $payment['id'])->exists()
                || ($eventId && GatewayPayment::where('provider_event_id', $eventId)->exists()), 422, 'Duplicate provider identifier.');
            $attempt->update([
                'status' => 'paid', 'provider_payment_id' => $payment['id'], 'provider_event_id' => $eventId,
                'provider_method' => data_get($attributes, 'source.type'),
                'paid_at' => isset($attributes['paid_at'])
                    ? (is_int($attributes['paid_at']) ? Carbon::createFromTimestampUTC($attributes['paid_at']) : Carbon::parse($attributes['paid_at']))->timezone(config('app.timezone')) : now(),
                'reconciliation_reason' => null,
            ]);
            if ($attempt->context === 'grooming') {
                if ($payable->hasSuccessfulPayment()) {
                    $attempt->update(['reconciliation_reason' => 'booking_already_paid']);
                } else {
                    app(GroomingPaymentSettlementService::class)->settle(
                        $payable, $attempt->amount, $attempt->amount, 'online', $attempt->snapshot['notes'],
                        User::find($attempt->initiated_by), $attempt->flow === 'early', $attempt->snapshot['products'], $attempt,
                    );
                }
            } elseif ($attempt->context === 'clinic') {
                if ($payable->paid) {
                    $attempt->update(['reconciliation_reason' => 'clinic_already_paid']);
                } else {
                    app(\App\Services\ClinicPaymentSettlementService::class)->settle(
                        $payable, $attempt->amount, $attempt->provider_method ?? 'online', $attempt->initiated_by, null, $attempt,
                    );
                }
            } else {
                $products = $attempt->snapshot['products'];
                $items = \App\Models\InventoryItem::whereIn('item_id', collect($products)->pluck('item_id'))
                    ->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
                $reservations = DB::table('gateway_stock_reservations')->where('gateway_payment_id', $attempt->id)->pluck('quantity', 'item_id');
                // A confirmed payment is retained for reconciliation if external inventory changes broke a reservation.
                try {
                    DB::transaction(function () use ($attempt, $payable, $products, $items, $reservations) {
                        $lines = [];
                        foreach ($products as $product) {
                            $item = $items->get($product['item_id']);
                            if (! $item || ! $item->is_active || (int) $reservations->get($item->item_id) !== $product['quantity']) {
                                throw \Illuminate\Validation\ValidationException::withMessages(['stock' => 'Reserved stock needs reconciliation.']);
                            }
                            $item->item_name = $product['name']; $item->unit = $product['unit'];
                            $lines[] = ['item' => $item, 'quantity' => $product['quantity'],
                                'price_at_sale' => $product['price_at_sale'], 'subtotal' => $product['subtotal']];
                        }
                        $attempt->releaseStock();
                        app(\App\Services\PosSaleService::class)->settle($payable, $lines,
                            (float) $attempt->amount, (float) $attempt->amount, $attempt->snapshot['notes'], $attempt);
                    });
                } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    $attempt->update(['reconciliation_reason' => 'product_stock_conflict']);
                }
            }
            return response()->json(['success' => true]);
        });
    }
}
