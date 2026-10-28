<?php

namespace App\Jobs;

use App\Services\CustomerRegistrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

// Every public request queues the same encrypted work before any account lookup.
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
