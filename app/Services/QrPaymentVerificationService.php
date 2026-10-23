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
            'external_reference' => 'nullable|string|max:100|regex:/^[A-Za-z0-9 -]+$/D',
            'transaction_at' => 'required_if:payment_method,qrph|nullable|date_format:Y-m-d\TH:i|before_or_equal:now',
            'reference_unavailable_reason' => 'nullable|string|min:20|max:500',
            'exception_verified' => 'nullable|boolean',
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
        $settings = QrPaymentSettings::whereKey(1)->sharedLock()->first();
        if (! $settings?->available() || $settings->version !== (int) $data['qr_settings_version']) {
            throw ValidationException::withMessages(['qr_settings_version' => 'QR payments are unavailable or the QR has changed. Reopen the payment before collecting funds.']);
        }
        if (! isset($data['amount_paid']) || (int) round((float) $data['amount_paid'] * 100) !== (int) round((float) $total * 100)) {
            throw ValidationException::withMessages(['amount_paid' => 'Amount Received must exactly match the amount due.']);
        }
        $reference = self::normalizeReference($data['external_reference'] ?? null);
        $reason = trim($data['reference_unavailable_reason'] ?? '');
        if ($reference === null) {
            if (mb_strlen($reason) < 20 || empty($data['exception_verified'])) {
                throw ValidationException::withMessages(['external_reference' => 'Enter the official reference. An administrator may document an exception when no reference is available.']);
            }
            abort_unless($actor->role === 'admin', 403, 'Only an administrator can verify a payment without an official reference.');
        } elseif (! preg_match('/^[A-Z0-9]{6,80}$/D', $reference)) {
            throw ValidationException::withMessages(['external_reference' => 'Enter a valid reference of 6–80 letters or numbers.']);
        }
        if ($reference && QrPayment::where('external_reference', $reference)->exists()) {
            throw ValidationException::withMessages(['external_reference' => 'This external reference has already been recorded.']);
        }
        return QrPayment::create([
            'payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->getKey(),
            'external_reference' => $reference, 'received_amount' => $total,
            'transaction_at' => $data['transaction_at'], 'verification_source' => 'receiving_history',
            'verified_by' => $actor->user_id, 'verified_by_name' => trim($actor->first_name.' '.$actor->last_name),
            'verified_at' => now(), 'reference_unavailable_reason' => $reference ? null : $reason,
            'settings_version' => $settings->version, 'merchant_name' => $settings->merchant_name,
            'provider_name' => $settings->provider_name, 'qr_image_hash' => $settings->image_hash,
        ]);
    }
}
