<?php

namespace App\Services;

use App\Models\QrPayment;
use App\Models\QrPaymentSettings;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class QrPaymentVerificationService
{
    public static function rules(): array
    {
        return [
            'qr_settings_version' => 'required_if:payment_method,qrph|nullable|integer|min:1',
            'qr_verified' => 'exclude_unless:payment_method,qrph|required|accepted',
            'external_reference' => 'required_if:payment_method,qrph|nullable|string|max:100|regex:/^[A-Za-z0-9 -]+$/D',
            'transaction_at' => 'exclude_unless:payment_method,qrph|nullable|date_format:Y-m-d\TH:i|before_or_equal:now',
            'observed_amount' => 'nullable|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'payment_source' => 'exclude_unless:payment_method,qrph|required|string|max:100',
            'paying_app' => ['exclude_unless:payment_method,qrph', 'nullable', \Illuminate\Validation\Rule::in(['GCash', 'Maya'])],
            'receipt' => 'exclude_unless:payment_method,qrph|nullable|file|max:5120|mimes:png,jpg,jpeg,webp,pdf|mimetypes:image/png,image/jpeg,image/webp,application/pdf',
        ];
    }

    public static function normalizeReference(?string $reference): ?string
    {
        $normalized = strtoupper(preg_replace('/[ -]/', '', $reference ?? ''));
        return $normalized === '' ? null : $normalized;
    }

    /** Caller holds the payable lock in the same transaction as settlement. */
    public function verify(Model $payable, array $data, string $total, User $actor): QrPayment
    {
        // Legacy manual verification stays preserved behind the source-controlled guard.
        if (! config('qr_payments.enabled', false)) {
            throw ValidationException::withMessages(['payment_method' => 'The selected payment method is invalid.']);
        }
        $settings = QrPaymentSettings::whereKey(1)->sharedLock()->first();
        if (! $settings?->available() || $settings->version !== (int) $data['qr_settings_version']) {
            throw ValidationException::withMessages(['qr_settings_version' => 'QR payments are unavailable or the QR has changed. Reopen the payment before collecting funds.']);
        }
        if (! in_array($data['payment_source'], $settings->acceptedMethods(), true)) {
            throw ValidationException::withMessages(['payment_source' => 'This payment method is unavailable. Reopen the payment to load current methods.']);
        }
        foreach (['amount_paid', 'observed_amount'] as $field) {
            if (isset($data[$field]) && (int) round((float) $data[$field] * 100) !== (int) round((float) $total * 100)) {
                throw ValidationException::withMessages([$field => 'Received amount differs from the server-calculated Total Due. Leave payment unresolved until reconciled.']);
            }
        }
        $reference = self::normalizeReference($data['external_reference'] ?? null);
        if (! $reference || ! preg_match('/^[A-Z0-9]{6,80}$/D', $reference)) {
            throw ValidationException::withMessages(['external_reference' => 'Enter the Payment Reference No. of 6–80 letters or numbers.']);
        }
        if ($reference && QrPayment::where('external_reference', $reference)->exists()) {
            throw ValidationException::withMessages(['external_reference' => 'This Payment Reference No. has already been recorded.']);
        }
        return QrPayment::create(($data['_proof'] ?? []) + [
            'payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->getKey(),
            'external_reference' => $reference, 'received_amount' => $total,
            'transaction_at' => $data['transaction_at'] ?? null, 'verification_source' => 'receiving_history',
            'verified_by' => $actor->user_id, 'verified_by_name' => trim($actor->first_name.' '.$actor->last_name),
            'verified_at' => now(), 'paying_app' => $data['paying_app'] ?? null, 'payment_source' => $data['payment_source'],
            'settings_version' => $settings->version, 'merchant_name' => $settings->merchant_name,
            'qr_image_hash' => $settings->image_hash,
        ]);
    }
}
