<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class IprogSmsOtpService
{
    public function configured(): bool
    {
        return trim((string) config('services.iprogsms.api_token')) !== '' && (string) config('app.key') !== '';
    }

    public function send(string $phone, string $generation): ?Carbon
    {
        $minutes = (int) config('services.iprogsms.otp_expires_in_minutes', 5);
        $started = now();
        $data = $this->post('send_otp', ['phone_number' => $phone, 'expires_in_minutes' => $minutes]);
        if (($data['status'] ?? null) !== 'success' || ! is_array($data['data'] ?? null)) {
            return null;
        }
        $otp = $data['data'];
        if (($otp['phone_number'] ?? null) !== $phone || ($otp['otp_code_confirmed'] ?? null) !== false
            || ! is_string($otp['otp_code'] ?? null) || ! preg_match('/^[0-9]{6}$/D', $otp['otp_code'])
            || ! is_string($otp['otp_code_expires_at'] ?? null)) {
            return null;
        }
        try {
            $expires = Carbon::parse($otp['otp_code_expires_at']);
        } catch (\Throwable) {
            return null;
        }
        if ($expires->lte(now()) || $expires->gt($started->copy()->addMinutes($minutes)->addSeconds(10))) {
            return null;
        }

        // Only a keyed fingerprint leaves this boundary. The provider still verifies ownership.
        $this->sentFingerprint = $this->fingerprint($generation, $otp['otp_code']);

        return $expires->min($started->addMinutes($minutes));
    }

    private ?string $sentFingerprint = null;

    public function sentFingerprint(): ?string
    {
        return $this->sentFingerprint;
    }

    public function fingerprint(string $generation, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $generation.':'.$code, (string) config('app.key'));
    }

    public function verify(string $phone, #[\SensitiveParameter] string $code): bool
    {
        $data = $this->post('verify_otp', ['phone_number' => $phone, 'otp' => $code]);

        return ($data['status'] ?? null) === 'success'
            && ($data['message'] ?? null) === 'OTP verified successfully';
    }

    private function post(string $operation, array $payload): array
    {
        if (! $this->configured()) {
            return [];
        }
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->post(rtrim(config('services.iprogsms.base_url'), '/').'/api/v1/otp/'.$operation,
                    ['api_token' => config('services.iprogsms.api_token'), ...$payload]);
            $data = $response->json();

            return $response->successful() && is_array($data) ? $data : [];
        } catch (\Throwable) {
            // Never report a request exception: it may include credentials or an OTP.
            return [];
        }
    }
}
