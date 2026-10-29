<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct()
    {
        $this->afterCommit();
        // Security confirmations always use the existing durable mail queue.
        $this->onConnection('database');
        if ($queue = config('mail.authentication.queue')) {
            $this->onQueue($queue);
        }
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Password Changed - Bethlehem Animal Clinic')
            ->greeting("Hi {$notifiable->first_name}!")
            ->line('Your password was changed for your Bethlehem Animal Clinic account.')
            ->line('If you did not make this change, contact Bethlehem Animal Clinic for help.');
    }
}
