<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PayMongoService
{
    public const SUPPORTED_PAYMENT_METHODS = ['qrph', 'gcash', 'paymaya', 'card'];

    public function assertConfigured(): void
    {
        $this->assertCredentials($this->livemode());
        $this->paymentMethods();
    }

    private function credential(bool $livemode, string $name): string
    {
        return (string) config('services.paymongo.'.($livemode ? 'live' : 'test').'.'.$name);
    }

    private function assertCredentials(bool $livemode): void
    {
        $key = $this->credential($livemode, 'secret_key');
        $prefix = $livemode ? 'sk_live_' : 'sk_test_';
        if (! str_starts_with($key, $prefix) || strlen($key) <= strlen($prefix)
            || trim($this->credential($livemode, 'webhook_secret')) === '') {
            throw ValidationException::withMessages(['payment' => 'Online payment is not configured.']);
        }
    }

    public function paymentMethods(): array
    {
        $methods = config('services.paymongo.payment_methods', []);
        if (! is_array($methods) || ! array_is_list($methods) || $methods === []
            || ! collect($methods)->every(fn ($method) => is_string($method) && in_array($method, self::SUPPORTED_PAYMENT_METHODS, true))
            || count(array_unique($methods)) !== count($methods)) {
            throw ValidationException::withMessages(['payment' => 'Online payment methods are not configured.']);
        }
        return $methods;
    }

    public function livemode(): bool
    {
        return (bool) config('services.paymongo.livemode', false);
    }

    public function createCheckout(array $attributes, bool $livemode): array
    {
        return $this->request('post', '/v2/checkout_sessions', $livemode, ['data' => ['attributes' => $attributes]]);
    }

    public function retrieveCheckout(string $id, bool $livemode): array
    {
        return $this->request('get', '/v1/checkout_sessions/'.rawurlencode($id), $livemode);
    }

    public function expireCheckout(string $id, bool $livemode): array
    {
        return $this->request('post', '/v1/checkout_sessions/'.rawurlencode($id).'/expire', $livemode);
    }

    private function request(string $method, string $path, bool $livemode, array $payload = []): array
    {
        // New checkout settings must not prevent recovery/expiry of older sessions.
        $this->assertCredentials($livemode);
        try {
            $response = Http::withBasicAuth($this->credential($livemode, 'secret_key'), '')
                ->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->{$method}('https://api.paymongo.com'.$path, $payload);
        } catch (ConnectionException) {
            // Do not report HTTP exceptions: they may contain authorization headers.
            return ['ok' => false, 'uncertain' => true];
        }

        return ['ok' => $response->successful(), 'uncertain' => ! in_array($response->status(), [400, 401, 403, 404, 405, 422], true),
            'data' => $response->json('data') ?? []];
    }

    /** null is invalid; false authenticates Test; true authenticates Live. */
    public function authenticatedMode(string $rawBody, string $header): ?bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if (array_key_exists($name, $parts)) return null;
            $parts[$name] = $value;
        }
        $timestamp = $parts['t'] ?? '';
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) return null;
        $authenticated = null;
        foreach (['te' => false, 'li' => true] as $channel => $livemode) {
            $secret = $this->credential($livemode, 'webhook_secret');
            $signature = $parts[$channel] ?? '';
            if ($secret !== '' && $signature !== ''
                && hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $signature)) {
                if ($authenticated !== null) return null;
                $authenticated = $livemode;
            }
        }
        return $authenticated;
    }
}
