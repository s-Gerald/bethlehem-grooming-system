<?php

namespace App\Notifications\Concerns;

use App\Services\AuthenticationMailDelivery;
use Throwable;

trait TracksAuthenticationMail
{
    public ?string $deliveryReceipt = null;

    public function backoff(): array
    {
        return [5, 15];
    }

    public function failed(Throwable $exception): void
    {
        if ($this->deliveryReceipt && ! in_array(AuthenticationMailDelivery::status($this->deliveryReceipt), ['sent', 'superseded'], true)) {
            AuthenticationMailDelivery::record($this->deliveryReceipt, 'failed');
        }
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Pre-release OTP jobs cannot prove which issuance they belong to.
        // Suppress them rather than mailing a possibly replaced code. Existing
        // verification links remain supported (those notifications have no code).
        return $this->deliveryReceipt !== null || ! isset($this->code);
    }
}
