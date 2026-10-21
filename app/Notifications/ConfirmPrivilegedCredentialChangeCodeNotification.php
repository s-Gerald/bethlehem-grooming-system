<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmPrivilegedCredentialChangeCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use \App\Notifications\Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct(
        public readonly string $code,
        public readonly string $purposeLabel,
        public readonly string $targetName,
        public readonly bool $targetsAdmin,
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
        $accountDescription = $this->targetsAdmin
            ? 'your administrator account'
            : "the staff account for {$this->targetName}";

        return (new MailMessage)
            ->subject("Security code for {$this->purposeLabel} - Bethlehem Animal Clinic")
            ->greeting("Hi {$notifiable->first_name}!")
            ->line("A request was made to complete a {$this->purposeLabel} for {$accountDescription}.")
            ->line('Your six-digit security code is:')
            ->line("**{$this->code}**")
            ->line("This code expires in {$expiryMinutes} minutes and can only be used once.")
            ->line('If you did not request this change, do not share the code and review administrator access immediately.');
    }
}
