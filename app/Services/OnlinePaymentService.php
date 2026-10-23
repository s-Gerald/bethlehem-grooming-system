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

    /** Lock order shared by cash, QR, checkout cancellation and webhook settlement. */
    public function locked(GatewayPayment $attempt, \Closure $callback): mixed
    {
        $model = match ($attempt->context) {
            'grooming' => \App\Models\Booking::class,
            'clinic' => \App\Models\ClinicAppointment::class,
            'pos' => \App\Models\User::class,
            default => null,
        };
        abort_unless($model && $attempt->payable_type === (new $model)->getMorphClass(), 422, 'Unknown online payment.');
        return DB::transaction(function () use ($attempt, $callback, $model) {
            $payable = $model::whereKey($attempt->payable_id)->lockForUpdate()->firstOrFail();
            $current = GatewayPayment::where('provider', 'paymongo')->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            return $callback($current, $payable);
        }, 3);
    }

    /** The attempt/reservations are committed before this single external request. */
    public function create(GatewayPayment $attempt): GatewayPayment
    {
        if (! $attempt->wasRecentlyCreated) return $attempt;
        $result = $this->provider->createCheckout([
            'line_items' => $attempt->snapshot['line_items'],
            'payment_method_types' => config('services.paymongo.payment_methods'),
            'reference_number' => $attempt->reference, 'pass_on_fees' => false,
            'success_url' => $this->returnUrl($attempt, 'success'), 'cancel_url' => $this->returnUrl($attempt, 'cancel'),
            'show_line_items' => true, 'metadata' => $this->metadata($attempt),
        ]);
        $this->locked($attempt, function (GatewayPayment $current) use ($result) {
            if ($current->status !== 'pending') return;
            $session = $result['data'] ?? [];
            $url = data_get($session, 'attributes.checkout_url');
            if ($result['ok'] && is_string($session['id'] ?? null) && str_starts_with($session['id'], 'cs_')
                && ($session['type'] ?? null) === 'checkout_session' && is_string($url)
                && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'checkout.paymongo.com'
                && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PORT)
                && data_get($session, 'attributes.livemode') === false) {
                $current->update(['checkout_session_id' => $session['id'], 'checkout_url' => $url, 'reconciliation_reason' => null]);
            } elseif (! $result['ok'] && ! $result['uncertain']) {
                $current->update(['status' => 'failed', 'reconciliation_reason' => null]);
                $current->releaseStock();
            } elseif (is_string($session['id'] ?? null) && str_starts_with($session['id'], 'cs_')) {
                $current->update(['checkout_session_id' => $session['id']]);
            }
        });
        return $attempt->refresh();
    }

    private function safeExpiry(GatewayPayment $attempt, array $result): bool
    {
        $session = $result['data'] ?? [];
        if (! $result['ok'] || ($session['id'] ?? null) !== $attempt->checkout_session_id
            || ($session['type'] ?? null) !== 'checkout_session'
            || data_get($session, 'attributes.livemode') !== $attempt->livemode
            || data_get($session, 'attributes.status') !== 'expired') return false;
        // V2 may defer intents, or contain several intents after retries. Inspect all
        // nested intent/payment resources; an ambiguous status must retain the hold.
        $safe = function ($node) use (&$safe): bool {
            if (! is_array($node)) return false;
            if (in_array($node['type'] ?? null, ['payment', 'payment_intent'], true)
                && ! isset($node['attributes']['status'])) return false;
            if (isset($node['attributes']['status'])) {
                $status = $node['attributes']['status'];
                $allowed = match ($node['type'] ?? '') {
                    'payment' => ['failed', 'cancelled'],
                    'payment_intent' => ['awaiting_payment_method', 'cancelled', 'failed'],
                    default => [],
                };
                if (! in_array($status, $allowed, true)) return false;
            }
            foreach ($node as $key => $value) {
                if (is_array($value) && ! $safe($value)) return false;
                if ($key === 'status' && in_array($value, ['paid', 'pending', 'processing', 'succeeded', 'awaiting_next_action'], true)) return false;
            }
            return true;
        };
        foreach (['payments', 'payment_intent', 'payment_intents'] as $key) {
            $resources = data_get($session, 'attributes.'.$key);
            if ($resources !== null && ! $safe($resources)) return false;
        }
        return true;
    }

    /** Retrieval may confirm expiry, but can never settle a payment. No HTTP under locks. */
    public function refreshStatus(GatewayPayment $attempt): GatewayPayment
    {
        if ($attempt->status !== 'pending' || ! $attempt->checkout_session_id) return $attempt;
        $result = $this->provider->retrieveCheckout($attempt->checkout_session_id);
        $this->locked($attempt, function (GatewayPayment $current) use ($result) {
            if ($current->status === 'pending' && $this->safeExpiry($current, $result)) {
                $current->update(['status' => 'expired', 'reconciliation_reason' => null]);
                $current->releaseStock();
            }
        });
        return $attempt->refresh();
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
        $this->locked($attempt, function (GatewayPayment $current) use ($result) {
            abort_if($current->status === 'paid', 409, 'This payment has already been completed.');
            if ($current->status !== 'pending') return;
            if (! $this->safeExpiry($current, $result)) {
                throw ValidationException::withMessages(['payment' => 'Online payment is still pending.']);
            }
            $current->update(['status' => 'cancelled', 'reconciliation_reason' => null]);
            $current->releaseStock();
        });
        return $attempt->refresh();
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
