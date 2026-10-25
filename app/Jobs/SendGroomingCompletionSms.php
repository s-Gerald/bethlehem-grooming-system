<?php

namespace App\Jobs;

use App\Services\GroomingCompletionSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendGroomingCompletionSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 20;

    public function __construct(public readonly int $eventId)
    {
        $this->onConnection('database');
    }

    public function handle(GroomingCompletionSmsService $sms): void
    {
        $sms->deliver($this->eventId);
    }
}
