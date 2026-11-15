<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GroomingConsent;
use Illuminate\Validation\Rule;

class GroomingConsentCapture
{
    public static function agreement(): array
    {
        return json_decode(file_get_contents(base_path('scripts/data/grooming-consent.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function rules(): array
    {
        return [
            'terms_agreed' => ['required', 'accepted'],
            'sedation_consent' => ['required', 'boolean'],
            'signer_name' => SignatureCapture::signerNameRules(),
            'consent_version' => ['required', Rule::in([self::agreement()['version']])],
            ...SignatureCapture::rules(),
        ];
    }

    public static function capture(Booking $booking, array $data): GroomingConsent
    {
        return GroomingConsent::create([
            'booking_id' => $booking->booking_id,
            'grooming_consent' => true,
            'sedation_consent' => (bool) $data['sedation_consent'],
            'signer_name' => trim($data['signer_name']),
            'signature_image' => SignatureCapture::image($data['signature_strokes']),
            'signature_strokes' => $data['signature_strokes'],
            'signed_at' => now(),
            'consent_version' => self::agreement()['version'],
            'agreement_snapshot' => self::agreement(),
        ]);
    }
}
