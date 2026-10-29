<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Services\CustomerRegistrationService;
use App\Services\RegistrationMagicLinkService;

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
        return isset($this->token) && strlen($this->token) === 64
            && ($notifiable instanceof PendingCustomerRegistration || $notifiable instanceof User)
            && ($notifiable instanceof PendingCustomerRegistration ? ! $notifiable->retentionExpired()
                : CustomerRegistrationService::recoverableUser($notifiable))
            && hash_equals((string) $notifiable->email_verification_token, hash('sha256', $this->token))
            && $notifiable->email_verification_last_sent_at
            && RegistrationMagicLinkService::expiresAt($notifiable)?->greaterThanOrEqualTo(now()->addSeconds(RegistrationMagicLinkService::MIN_DELIVERY_SECONDS));
    }

    public function toMail(object $notifiable): MailMessage
    {
        \App\Services\RegistrationMagicLinkService::assertMailerDoesNotLogSecrets();
        $expiry = RegistrationMagicLinkService::expiresAt($notifiable);
        if (! $expiry || ! $expiry->isFuture()) throw new \RuntimeException('Verification link has expired.');
        return (new MailMessage)
            ->subject('Verify your Bethlehem account')
            ->line('Confirm your email to activate your Bethlehem account.')
            ->action('Verify Email Address', rtrim(config('app.frontend_url'), '/').'/pages/client/verify-email.html#token='.rawurlencode($this->token))
            ->line('This link expires on '.$expiry->timezone('Asia/Manila')->format('F j, Y, g:i:s A').' (Asia/Manila, UTC+08:00).')
            ->line('If you did not start this registration, ignore this email.');
    }
}
