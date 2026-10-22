<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use \App\Notifications\Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    // Hydrated only by notification jobs queued before the code rollout.
    private ?string $verificationUrl = null;

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
        if (! isset($this->code) && $this->verificationUrl) {
            return (new MailMessage)
                ->subject('Verify your Bethlehem account')
                ->line('Finish creating your Bethlehem Animal Clinic account.')
                ->action('Verify Email Address', $this->verificationUrl)
                ->line('If you did not create a Bethlehem account, you can ignore this email.');
        }

        // Recheck in the queue worker, where the mail configuration may differ.
        \App\Services\RegistrationEmailCodeService::assertMailerDoesNotLogCodes();

        return (new MailMessage)
            ->subject('Your Bethlehem verification code')
            ->view('mail.registration-code', ['code' => $this->code, 'ttlMinutes' => \App\Services\RegistrationEmailCodeService::TTL_MINUTES])
            ->text('mail.registration-code-text', ['code' => $this->code, 'ttlMinutes' => \App\Services\RegistrationEmailCodeService::TTL_MINUTES]);
    }
}
