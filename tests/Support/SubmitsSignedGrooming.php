<?php

namespace Tests\Support;

trait SubmitsSignedGrooming
{
    private function groomingConsentFixture(): array
    {
        return [
            'terms_agreed' => true,
            'sedation_consent' => false,
            'signer_name' => 'Customer Representative',
            'signature_strokes' => [[[20, 70], [60, 30], [110, 80]]],
            'consent_version' => \App\Services\GroomingConsentCapture::agreement()['version'],
        ];
    }

    private function submitSignedGrooming(string $uri, array $payload = [])
    {
        if ($uri === '/api/booking/store' && ! array_key_exists('service_day', $payload)) {
            $payload['service_day'] = now('Asia/Manila')->toDateString();
        }
        return $this->postJson($uri, array_replace($this->groomingConsentFixture(), $payload));
    }
}
