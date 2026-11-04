<?php

namespace App\Jobs;

use App\Services\CustomerRegistrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

// Generic verification resend work. Old creation payloads are safely ignored.
class ProcessPublicRegistration implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $email,
        public ?array $details,
        public string $browserHash,
        public int $requestedAt,
    ) {
        if ($queue = config('mail.authentication.queue')) $this->onQueue($queue);
    }

    public function handle(CustomerRegistrationService $registrations): void
    {
        $registrations->processPublicRequest($this->email, $this->details, $this->browserHash, $this->requestedAt);
    }
}
