<?php

namespace App\Http\Controllers;

use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\GroomingPaymentReadinessService;
use App\Services\GroomingPaymentSettlementService;
use App\Services\OnlinePaymentService;
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
        // Official event resource plus the send.webhook wrapper supported by historical clients.
        $resourceEnvelope = data_get($payload, 'data.type') === 'event';
        abort_unless($resourceEnvelope || ($payload['event_type'] ?? null) === 'send.webhook', 400, 'Unknown webhook envelope.');
        $event = $resourceEnvelope ? data_get($payload, 'data.attributes', []) : ($payload['data'] ?? []);
        if (($event['type'] ?? null) !== 'checkout_session.payment.paid') return response()->json(['success' => true]);
        abort_unless(($event['livemode'] ?? null) === $provider->livemode(), 422, 'Webhook environment mismatch.');
        $session = $event['data'] ?? [];
        $sessionId = $session['id'] ?? null;
        abort_unless(is_string($sessionId) && ($session['type'] ?? null) === 'checkout_session', 422, 'Checkout Session is missing.');
        $existing = GatewayPayment::where('provider', 'paymongo')->where('checkout_session_id', $sessionId)->first();
        abort_unless($existing, 422, 'Unknown online payment.');

        return app(OnlinePaymentService::class)->locked($existing, function (GatewayPayment $attempt, $payable) use ($payload, $event, $session, $sessionId, $provider) {
            $payments = collect(data_get($session, 'attributes.payments', []))
                ->filter(fn ($payment) => data_get($payment, 'attributes.status') === 'paid')->values();
            abort_unless($payments->count() === 1, 422, 'A paid payment is required.');
            $payment = $payments->first();
            $attributes = $payment['attributes'];
            $eventId = data_get($payload, 'data.type') === 'event'
                ? data_get($payload, 'data.id') : ($payload['id'] ?? $payload['event_id'] ?? $event['id'] ?? null);
            abort_unless(is_string($eventId) && str_starts_with($eventId, 'evt_'), 422, 'Event identifier is missing.');
            $paidAt = $attributes['paid_at'] ?? null;
            abort_unless($paidAt === null || (is_int($paidAt) && $paidAt > 0 && $paidAt <= time() + 300), 422, 'Invalid provider payment time.');
            $money = app(GroomingPaymentReadinessService::class);
            abort_unless($attempt->checkout_session_id === $sessionId
                && $attempt->livemode === $provider->livemode()
                // The signed event binds the environment. Abbreviated official
                // envelopes may omit nested flags; any supplied flag must match.
                && (! array_key_exists('livemode', $session['attributes']) || $session['attributes']['livemode'] === $attempt->livemode)
                && (! array_key_exists('livemode', $attributes) || $attributes['livemode'] === $attempt->livemode)
                && $attempt->currency === 'PHP' && ($attributes['currency'] ?? null) === 'PHP'
                && is_int($attributes['amount'] ?? null)
                && $attributes['amount'] === $money->moneyToCents($attempt->amount)
                && $money->moneyToCents($attempt->snapshot['total']) === $attributes['amount']
                && is_string($payment['id'] ?? null) && str_starts_with($payment['id'], 'pay_')
                && ($payment['type'] ?? null) === 'payment'
                && (($attempt->snapshot['metadata_version'] ?? 1) < 2
                    || data_get($session, 'attributes.reference_number') === $attempt->reference)
                && data_get($session, 'attributes.metadata.gateway_payment_id') === (string) $attempt->id
                && collect(app(OnlinePaymentService::class)->metadata($attempt))->every(fn ($value, $key) =>
                    (($attempt->snapshot['metadata_version'] ?? 1) < 2 && $key === 'context' && $attempt->context === 'grooming' && data_get($session, 'attributes.metadata.context') === null)
                    || data_get($session, 'attributes.metadata.'.$key) === $value),
                422, 'Online payment details do not match.');
            $method = data_get($attributes, 'source.type');
            // Historical attempts predate method snapshots; retain their supported channels.
            $allowedMethods = $attempt->snapshot['payment_methods'] ?? PayMongoService::SUPPORTED_PAYMENT_METHODS;
            abort_unless(is_string($method) && in_array($method === 'maya' ? 'paymaya' : $method, $allowedMethods, true), 422, 'Online payment method does not match.');
            if ($attempt->status === 'paid') {
                abort_unless($attempt->provider_payment_id === $payment['id'], 422, 'Payment identifier mismatch.');
                return response()->json(['success' => true]);
            }
            abort_if(GatewayPayment::where('provider_payment_id', $payment['id'])->exists()
                || ($eventId && GatewayPayment::where('provider_event_id', $eventId)->exists()), 422, 'Duplicate provider identifier.');
            $wasClosed = in_array($attempt->status, ['failed', 'cancelled', 'expired'], true);
            $attempt->update([
                'status' => 'paid', 'provider_payment_id' => $payment['id'], 'provider_event_id' => $eventId,
                'provider_method' => data_get($attributes, 'source.type'),
                'paid_at' => $paidAt === null ? now() : Carbon::createFromTimestampUTC($paidAt)->timezone(config('app.timezone')),
                'reconciliation_reason' => null,
            ]);
            if ($wasClosed) {
                $attempt->update(['reconciliation_reason' => 'checkout_closed_before_confirmation']);
                return response()->json(['success' => true]);
            }
            if ($attempt->context === 'grooming') {
                if ($payable->hasSuccessfulPayment()) {
                    $attempt->update(['reconciliation_reason' => 'booking_already_paid']);
                } else {
                    try {
                        // Roll back the entire sale on a stock conflict, retaining the
                        // confirmed provider payment and its reservations for reconciliation.
                        DB::transaction(function () use ($payable, $attempt) {
                            $attempt->releaseStock();
                            $lines = app(\App\Services\GroomingProductSaleService::class)->prepareFrozen($attempt->snapshot['products']);
                            $actor = User::find($attempt->initiated_by);
                            abort_unless($actor || $lines === [], 422, 'Initiating staff is unavailable.');
                            app(GroomingPaymentSettlementService::class)->settle(
                                $payable, $attempt->amount, $attempt->amount, 'online', $attempt->snapshot['notes'],
                                $actor, $attempt->flow === 'early', $lines, $attempt,
                            );
                        });
                    } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                        $attempt->update(['reconciliation_reason' => 'product_stock_conflict']);
                    }
                }
            } elseif ($attempt->context === 'clinic') {
                if ($payable->paid) {
                    $attempt->update(['reconciliation_reason' => 'clinic_already_paid']);
                } else {
                    app(\App\Services\ClinicPaymentSettlementService::class)->settle(
                        $payable, $attempt->amount, 'online', $attempt->initiated_by, null, $attempt,
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
