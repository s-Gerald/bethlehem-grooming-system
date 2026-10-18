<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SetUpStaffPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use \App\Notifications\Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct(public readonly string $setupUrl)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set Up Your Password - Bethlehem Animal Clinic')
            ->greeting("Hi {$notifiable->first_name}!")
            ->line('Your Bethlehem Animal Clinic staff account has been created.')
            ->action('Set Up Your Password', $this->setupUrl)
            ->line('This setup link expires in 24 hours and can only be used once.')
            ->line('If you were not expecting this account, please contact the clinic administrator.');
    }
}
