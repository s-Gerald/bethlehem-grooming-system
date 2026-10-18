<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use \App\Notifications\Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct(public readonly string $code)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiryMinutes = max(1, (int) config('auth.passwords.users.expire', 15));

        return (new MailMessage)
            ->subject('Reset Your Password - Bethlehem Animal Clinic')
            ->greeting("Hi {$notifiable->first_name}!")
            ->line('A password reset was requested for your Bethlehem Animal Clinic account.')
            ->line("Your verification code is: {$this->code}")
            ->line("This code expires in {$expiryMinutes} minutes and can only be used to reset your password once.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
