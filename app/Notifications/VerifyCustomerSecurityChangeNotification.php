<?php

namespace App\Notifications;

use App\Services\CustomerSecurityChangeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyCustomerSecurityChangeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct(public readonly string $code, public readonly string $kind, public readonly string $changeId) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(CustomerSecurityChangeService::class)->canDeliver($this->changeId);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $purpose = $this->kind === 'email' ? 'verify your new email address' : 'confirm your password change';

        return (new MailMessage)
            ->subject(ucfirst($purpose).' - Bethlehem Animal Clinic')
            ->line("Use this code to {$purpose} for your Bethlehem Animal Clinic account.")
            ->line('**'.$this->code.'**')
            ->line('If you did not request this change, ignore this code and contact Bethlehem Animal Clinic.');
    }
}
