<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffEmailChangedNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->afterCommit();
        $this->onConnection('database');
        if ($queue = config('mail.authentication.queue')) $this->onQueue($queue);
    }

    public function backoff(): array { return [60, 300, 900]; }
    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Email Updated - Bethlehem Animal Clinic & Grooming')
            ->line('Your staff account email has been changed. Previous sessions have been signed out.')
            ->line('If you did not make this change, contact the clinic administrator immediately.');
    }
}
