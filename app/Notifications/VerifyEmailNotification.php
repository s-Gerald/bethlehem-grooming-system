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

    public function __construct(public readonly string $token)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Old queued signup codes cannot be used by the new verification API.
        return isset($this->token) && strlen($this->token) === 64;
    }

    public function toMail(object $notifiable): MailMessage
    {
        \App\Services\RegistrationMagicLinkService::assertMailerDoesNotLogSecrets();
        return (new MailMessage)
            ->subject('Verify your Bethlehem account')
            ->line('Confirm your email to activate your Bethlehem account.')
            ->action('Verify Email Address', rtrim(config('app.frontend_url'), '/').'/pages/client/verify-email.html#token='.rawurlencode($this->token))
            ->line('This link expires in '.(int) config('authentication.magic_link_minutes', 30).' minutes.')
            ->line('If you did not start this registration, ignore this email.');
    }
}
