<?php

namespace App\Console\Commands;

use App\Services\PreRegistrationExpiry;
use Illuminate\Console\Command;

class ExpirePreRegistrations extends Command
{
    protected $signature = 'pre-registrations:expire';
    protected $description = 'Mark unarrived Online Registrations No Show at their service closing deadline.';

    public function handle(PreRegistrationExpiry $expiry): void
    {
        $this->info('Marked '.$expiry->expire().' Online Registration(s) No Show.');
    }
}
