<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnusualSignInActivityNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    use Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Unusual sign-in activity - Bethlehem Animal Clinic')
            ->line('We detected unsuccessful sign-in verification attempts on your Bethlehem Animal Clinic account.')
            ->line("If this wasn't you, review your security activity and consider changing your password.");
    }
}
