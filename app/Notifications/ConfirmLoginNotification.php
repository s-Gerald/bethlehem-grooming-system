<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmLoginNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
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
        \App\Services\RegistrationMagicLinkService::assertMailerDoesNotLogSecrets();
        $expiryMinutes = max(1, (int) config('authentication.otp_minutes', 10));
        $accountLabel = $notifiable->role === 'staff' ? 'staff account' : 'account';
        $subject = $notifiable->role === 'staff'
            ? 'Staff Sign-In Code - Bethlehem Animal Clinic'
            : 'Your Sign-In Code - Bethlehem Animal Clinic';

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hi {$notifiable->first_name}!")
            ->line("We received a request to sign in to your Bethlehem Animal Clinic {$accountLabel}.")
            ->line('Your six-digit sign-in code is:')
            ->line("**{$this->code}**")
            ->line("This code expires in {$expiryMinutes} minutes.")
            ->line('If you did not try to sign in, you can safely ignore this email.');
    }
}
