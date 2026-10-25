<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IprogSmsOtpService
{
    private string $sendFailureStatus = 'uncertain';
    private ?int $httpStatus = null;
    private float $requestStarted = 0;
    private string $responseType = 'unknown';

    public function sendFailureStatus(): string { return $this->sendFailureStatus; }

    public function configured(): bool
    {
        return trim((string) config('services.iprogsms.api_token')) !== '' && (string) config('app.key') !== '';
    }

    public function send(string $phone, string $generation): ?Carbon
    {
        $this->sentFingerprint = null;
        $minutes = (int) config('services.iprogsms.otp_expires_in_minutes', 5);
        $started = now();
        $data = $this->post('send_otp', ['phone_number' => $phone, 'expires_in_minutes' => $minutes, 'message' => ClinicSmsMessage::otp()]);
        if ($data === []) return null;
        if (! is_array($data['data'] ?? null)) {
            $this->diagnose('send_otp', 'unexpected_response', ['has_data' => false]);
            return null;
        }
        $otp = $data['data'];
        if (($otp['phone_number'] ?? null) !== $phone) {
            $this->diagnose('send_otp', 'destination_mismatch', ['has_destination' => isset($otp['phone_number'])]);
            return null;
        }
        if (($otp['otp_code_confirmed'] ?? null) !== false
            || ! is_string($otp['otp_code'] ?? null) || ! preg_match('/^[0-9]{6}$/D', $otp['otp_code'])
            || ! is_string($otp['otp_code_expires_at'] ?? null)) {
            $this->diagnose('send_otp', 'unexpected_response', [
                'has_code' => array_key_exists('otp_code', $otp),
                'has_confirmation' => array_key_exists('otp_code_confirmed', $otp),
                'has_expiry' => array_key_exists('otp_code_expires_at', $otp),
            ]);
            return null;
        }
        try {
            $expires = Carbon::parse($otp['otp_code_expires_at']);
        } catch (\Throwable) {
            $this->diagnose('send_otp', 'invalid_expiry');
            return null;
        }
        if ($expires->lte(now()) || $expires->gt($started->copy()->addMinutes($minutes)->addSeconds(10))) {
            $this->diagnose('send_otp', 'invalid_expiry');
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

        if ($data === []) return false;
        if (($data['message'] ?? null) !== 'OTP verified successfully') {
            $this->diagnose('verify_otp', 'unexpected_response');
            return false;
        }

        return true;
    }

    public function sendMessage(string $phone, string $message): array
    {
        $result = ['status' => 'failed', 'provider_message_id' => null];
        $units = ClinicSmsMessage::units($message);
        if (! $this->configured() || ! preg_match('/^09[0-9]{9}$/D', $phone) || $units === null || $units > 160) return $result;
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false])->post(rtrim(config('services.iprogsms.base_url'), '/').'/api/v1/sms_messages', [
                    'api_token' => config('services.iprogsms.api_token'), 'phone_number' => $phone, 'message' => $message,
                ]);
            $data = $response->json();
            if ($response->successful() && ($data['status'] ?? null) === 200
                && is_string($data['message_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $data['message_id'])) {
                return ['status' => 'accepted', 'provider_message_id' => $data['message_id']];
            }
            // An explicit rejection is failed. A timeout/5xx/malformed acceptance
            // can have crossed the submission boundary and remains uncertain.
            if (is_array($data) && ($data['status'] ?? null) !== 200 && isset($data['status']) && $response->status() < 500) return $result;
        } catch (\Throwable) {
            // Request exceptions can include private provider data.
        }

        return ['status' => 'uncertain', 'provider_message_id' => null];
    }

    private function post(string $operation, array $payload): array
    {
        $this->requestStarted = microtime(true);
        $this->httpStatus = null;
        $this->responseType = 'unknown';
        $this->sendFailureStatus = 'uncertain';
        if (! $this->configured()) {
            $this->sendFailureStatus = 'failed';
            $this->diagnose($operation, 'configuration_unavailable');
            return [];
        }
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->post(rtrim(config('services.iprogsms.base_url'), '/').'/api/v1/otp/'.$operation,
                    ['api_token' => config('services.iprogsms.api_token'), ...$payload]);
            $this->httpStatus = $response->status();
            $type = strtolower($response->header('Content-Type'));
            $this->responseType = str_contains($type, 'json') ? 'json' : (str_contains($type, 'html') ? 'html' : 'other');
            $data = $response->json();
            $rejected = is_array($data) && ((($data['status'] ?? null) === 500 && ($data['message'] ?? null) === 'Invalid Token')
                || (($data['status'] ?? null) === 'error' && ($response->successful() || ($response->clientError() && $response->status() !== 408))));
            if ($rejected) {
                $this->sendFailureStatus = 'failed';
                $this->diagnose($operation, 'provider_rejected');
                return [];
            }
            if (! $response->successful()) {
                if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 408) $this->sendFailureStatus = 'failed';
                $this->diagnose($operation, 'http_error');
                return [];
            }
            if (! is_array($data) || ($data['status'] ?? null) !== 'success') {
                $this->diagnose($operation, 'unexpected_response');
                return [];
            }

            return $data;
        } catch (\Throwable $error) {
            $errno = null;
            for ($cause = $error; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof \GuzzleHttp\Exception\ConnectException || $cause instanceof \GuzzleHttp\Exception\RequestException) {
                    $value = $cause->getHandlerContext()['errno'] ?? null;
                    if (is_int($value)) { $errno = $value; break; }
                }
            }
            if (in_array($errno, [6, 7, 60], true)) $this->sendFailureStatus = 'failed';
            $category = match ($errno) { 60 => 'tls_verification_failed', 28 => 'request_timeout', default => 'connection_failed' };
            // Never report the exception, URL, request or raw handler context.
            $this->diagnose($operation, $category, ['curl_errno' => $errno]);
            return [];
        }
    }

    private function diagnose(string $operation, string $category, array $fields = []): void
    {
        Log::warning('iProgSMS OTP failure', [
            'operation' => $operation, 'category' => $category, 'http_status' => $this->httpStatus,
            'duration_ms' => max(0, (int) round((microtime(true) - $this->requestStarted) * 1000)),
            'response_type' => $this->responseType, ...$fields,
        ]);
    }
}
