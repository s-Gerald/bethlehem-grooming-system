<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyStaffAccountEmailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use \App\Notifications\Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct(
        public readonly string $code,
        public readonly string $staffLabel,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiryMinutes = max(
            1,
            (int) config('app.privileged_credential_change_code_ttl_minutes', 10),
        );

        return (new MailMessage)
            ->subject("Verify {$this->staffLabel} Email - Bethlehem Animal Clinic")
            ->greeting('Hello!')
            ->line("An administrator requested a {$this->staffLabel} account using this email address.")
            ->line('Your six-digit email verification code is:')
            ->line("**{$this->code}**")
            ->line("This code expires in {$expiryMinutes} minutes and can only be used once.")
            ->line('If you were not expecting this account, do not share the code.');
    }
}
