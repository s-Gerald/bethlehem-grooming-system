<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffEmailRecoveryNotice extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    use Concerns\TracksAuthenticationMail;
    public int $tries = 3;
    public function __construct(public readonly bool $completed)
    {
        $this->onConnection('database')->afterCommit();
        if ($queue = config('mail.authentication.queue')) $this->onQueue($queue);
    }
    public function backoff(): array { return [60, 300, 900]; }
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Staff Account Recovery - Bethlehem Animal Clinic')
            ->line($this->completed ? 'Your staff account email recovery has completed.' : 'A clinic administrator authorized email recovery for your staff account. Existing sign-in access is suspended during recovery.')
            ->line('If this was unauthorized, contact the clinic administrator in person or through the clinic’s established contact number.');
    }
}
