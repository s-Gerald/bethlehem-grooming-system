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
        $this->assertCredentials();
        $this->paymentMethods();
    }

    private function assertCredentials(): void
    {
        $key = (string) config('services.paymongo.secret_key');
        if ($this->livemode() || ! str_starts_with($key, 'sk_test_') || ! config('services.paymongo.webhook_secret')) {
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

    public function createCheckout(array $attributes): array
    {
        $this->assertConfigured();
        return $this->request('post', '/v2/checkout_sessions', ['data' => ['attributes' => $attributes]]);
    }

    public function retrieveCheckout(string $id): array
    {
        return $this->request('get', '/v1/checkout_sessions/'.rawurlencode($id));
    }

    public function expireCheckout(string $id): array
    {
        return $this->request('post', '/v1/checkout_sessions/'.rawurlencode($id).'/expire');
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        // New checkout settings must not prevent recovery/expiry of older sessions.
        $this->assertCredentials();
        try {
            $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')
                ->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->{$method}('https://api.paymongo.com'.$path, $payload);
        } catch (ConnectionException) {
            // Do not report HTTP exceptions: they may contain authorization headers.
            return ['ok' => false, 'uncertain' => true];
        }

        return ['ok' => $response->successful(), 'uncertain' => ! in_array($response->status(), [400, 401, 403, 404, 405, 422], true),
            'data' => $response->json('data') ?? []];
    }

    public function validSignature(string $rawBody, string $header): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '' || $this->livemode()) return false;
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$name] = $value;
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts[$this->livemode() ? 'li' : 'te'] ?? '';
        return ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
            && $signature !== ''
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $signature);
    }
}
