<?php

namespace App\Services;

use App\Models\GatewayPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnlinePaymentService
{
    public function __construct(private readonly PayMongoService $provider) {}

    public function metadata(GatewayPayment $attempt): array
    {
        $key = match ($attempt->context) {
            'clinic' => 'clinic_appointment_id', 'pos' => 'cashier_id', default => 'booking_id',
        };
        return ['gateway_payment_id' => (string) $attempt->id, $key => (string) $attempt->payable_id, 'context' => $attempt->context];
    }

    public function returnUrl(GatewayPayment $attempt, string $outcome): string
    {
        // The configured Grooming URL remains the deployment's frontend anchor.
        $anchor = (string) config('services.paymongo.return_url');
        $parts = parse_url($anchor);
        if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['https', 'http'], true)) {
            throw ValidationException::withMessages(['payment' => 'Online payment return URL is not configured.']);
        }
        $path = $parts['path'] ?? '';
        $admin = strpos($path, '/pages/admin/');
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($admin === false ? '' : substr($path, 0, $admin));
        $page = match ($attempt->context) {
            'clinic' => '/pages/admin/clinic.html', 'pos' => '/pages/admin/inventory/inventory.html', default => null,
        };
        $url = $page ? $base.$page : strtok($anchor, '?#');
        return $url.'?'.http_build_query([
            'gateway_payment' => $attempt->id, 'payment_context' => $attempt->context,
            'payment_return' => $outcome, 'payable' => $attempt->payable_id,
        ]).($attempt->context === 'pos' ? '#sell-product' : '');
    }

    /** Caller commits the snapshot/reservations before this external request. */
    public function create(GatewayPayment $attempt): GatewayPayment
    {
        if (! $attempt->wasRecentlyCreated) return $attempt;
        DB::transaction(function () use ($attempt) {
            $attempt = GatewayPayment::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $methods = array_values(array_filter(array_map('trim', config('services.paymongo.payment_methods'))));
            if ($attempt->context === 'pos') {
                $methods = $attempt->flow === 'qrph' ? ['qrph'] : array_values(array_diff($methods, ['qrph']));
            }
            if ($methods === []) {
                $attempt->update(['status' => 'failed', 'reconciliation_reason' => null]);
                $attempt->releaseStock();
                return;
            }
            $result = $this->provider->createCheckout([
                'line_items' => $attempt->snapshot['line_items'], 'payment_method_types' => $methods,
                'reference_number' => $attempt->reference, 'pass_on_fees' => false,
                'success_url' => $this->returnUrl($attempt, 'success'), 'cancel_url' => $this->returnUrl($attempt, 'cancel'),
                'show_line_items' => true, 'metadata' => $this->metadata($attempt),
            ]);
            $session = $result['data'] ?? [];
            $url = data_get($session, 'attributes.checkout_url');
            if ($result['ok'] && ! empty($session['id']) && is_string($url)
                && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'checkout.paymongo.com'
                && data_get($session, 'attributes.livemode') === false) {
                $attempt->update(['checkout_session_id' => $session['id'], 'checkout_url' => $url, 'reconciliation_reason' => null]);
            } elseif (! $result['ok'] && ! $result['uncertain']) {
                $attempt->update(['status' => 'failed', 'reconciliation_reason' => null]);
                $attempt->releaseStock();
            } elseif (! empty($session['id'])) {
                $attempt->update(['checkout_session_id' => $session['id']]);
            }
        });
        return $attempt->refresh();
    }

    private function safeExpiry(GatewayPayment $attempt, array $result): bool
    {
        $session = $result['data'] ?? [];
        return $result['ok'] && ($session['id'] ?? null) === $attempt->checkout_session_id
            && data_get($session, 'attributes.livemode', false) === false
            && data_get($session, 'attributes.status') === 'expired'
            && ! collect(data_get($session, 'attributes.payments', []))->contains(fn ($payment) =>
                in_array(data_get($payment, 'attributes.status'), ['paid', 'pending', 'processing'], true));
    }

    /** Called under the payable and attempt locks; retrieval never settles a payment. */
    public function refreshStatus(GatewayPayment $attempt): GatewayPayment
    {
        if ($attempt->status === 'pending' && $attempt->checkout_session_id
            && $this->safeExpiry($attempt, $this->provider->retrieveCheckout($attempt->checkout_session_id))) {
            $attempt->update(['status' => 'expired', 'reconciliation_reason' => null]);
            $attempt->releaseStock();
        }
        return $attempt;
    }

    public function cancel(GatewayPayment $attempt): GatewayPayment
    {
        abort_if($attempt->status === 'paid', 409, 'This payment has already been completed.');
        if ($attempt->status !== 'pending') return $attempt;
        if (! $attempt->checkout_session_id) {
            throw ValidationException::withMessages(['payment' => 'Checkout creation needs reconciliation. Contact an administrator.']);
        }
        $result = $this->provider->expireCheckout($attempt->checkout_session_id);
        if (! $result['ok']) $result = $this->provider->retrieveCheckout($attempt->checkout_session_id);
        if (! $this->safeExpiry($attempt, $result)) {
            throw ValidationException::withMessages(['payment' => 'Online payment is still pending.']);
        }
        $attempt->update(['status' => 'cancelled', 'reconciliation_reason' => null]);
        $attempt->releaseStock();
        return $attempt;
    }

    public function response(GatewayPayment $attempt): array
    {
        return ['success' => true, 'online_payment' => [
            'id' => $attempt->id, 'context' => $attempt->context, 'payable_id' => $attempt->payable_id,
            'status' => $attempt->status, 'amount' => $attempt->amount, 'checkout_url' => $attempt->checkout_url,
            'flow' => $attempt->flow, 'is_test' => ! $attempt->livemode,
            'reconciliation_reason' => $attempt->reconciliation_reason,
        ]];
    }
}
