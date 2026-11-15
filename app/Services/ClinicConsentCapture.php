<?php

namespace App\Services;

use App\Models\ClinicSetting;
use App\Models\ClinicVisitGroup;
use Illuminate\Http\Exceptions\HttpResponseException;

class ClinicConsentCapture
{
    private const REVIEW_UPDATED = 'The Clinic Care Agreement has changed. Review and sign the updated agreement.';

    public static function agreement(bool $lockForUpdate = false): array
    {
        $agreement = json_decode(file_get_contents(base_path('scripts/data/clinic-consent.json')), true, flags: JSON_THROW_ON_ERROR);
        $hours = ClinicSetting::current($lockForUpdate)->serviceAvailability('clinic')['operating_hours_label'];
        $agreement['operating_hours'] = $hours;
        foreach ($agreement['clauses'] as &$clause) {
            $clause['text'] = str_replace('{operating_hours}', $hours, $clause['text']);
        }

        return $agreement;
    }

    public static function hash(array $agreement): string
    {
        return hash('sha256', json_encode($agreement, JSON_THROW_ON_ERROR));
    }

    public static function rules(): array
    {
        $signatureRules = SignatureCapture::rules();
        foreach (['signature_strokes', 'signature_strokes.*', 'signature_strokes.*.*'] as $field) {
            array_unshift($signatureRules[$field], 'bail', 'list');
        }

        return [
            'terms_agreed' => ['required', 'accepted'],
            'signer_name' => SignatureCapture::signerNameRules(),
            'consent_version' => ['required', 'string', 'max:40'],
            'agreement_hash' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            ...$signatureRules,
        ];
    }

    public static function messages(): array
    {
        return [
            'terms_agreed.required' => 'Agree to the Clinic Care Agreement.',
            'terms_agreed.accepted' => 'Agree to the Clinic Care Agreement.',
            'signer_name.required' => 'Enter the signer’s full name.',
            'signer_name.not_regex' => 'Enter the signer’s full name.',
            'signature_strokes.required' => 'Please draw your signature.',
            'signature_strokes.min' => 'Please draw your signature.',
            'signature_strokes.*.min' => 'Please draw your signature.',
        ];
    }

    // Called inside the intake transaction, while the availability row is locked.
    public static function capture(ClinicVisitGroup $group, array $data): void
    {
        $agreement = self::agreement(lockForUpdate: true);
        $errors = [];
        if ($data['consent_version'] !== $agreement['version']) {
            $errors['consent_version'] = [self::REVIEW_UPDATED];
        }
        if (! hash_equals(self::hash($agreement), $data['agreement_hash'])) {
            $errors['agreement_hash'] = [self::REVIEW_UPDATED];
        }
        if ($errors) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => 'clinic_agreement_changed',
                'message' => self::REVIEW_UPDATED,
                'errors' => $errors,
            ], 422));
        }

        $group->consent()->create([
            'clinic_consent' => true,
            'signer_name' => trim($data['signer_name']),
            'signature_image' => SignatureCapture::image($data['signature_strokes']),
            'signature_strokes' => $data['signature_strokes'],
            'signed_at' => now(),
            'consent_version' => $agreement['version'],
            'agreement_snapshot' => $agreement,
        ]);
    }
}
