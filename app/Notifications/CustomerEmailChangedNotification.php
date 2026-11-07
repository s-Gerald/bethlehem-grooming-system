<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerEmailChangedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct()
    {
        $this->onConnection('database');
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
            ->subject('Email Address Changed - Bethlehem Animal Clinic')
            ->line('The registered email address for your Bethlehem Animal Clinic account was changed.')
            ->line('If you did not authorize this change, contact Bethlehem Animal Clinic immediately.');
    }
}
