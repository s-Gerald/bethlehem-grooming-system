<?php

namespace App\Notifications;

use App\Models\StaffEmailRecovery;
use App\Services\StaffEmailRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyAdminStaffRecoveryNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, Concerns\TracksAuthenticationMail;
    public int $tries = 3;
    public function __construct(public readonly string $code, public readonly string $recoveryId, public readonly string $expiresAt) { $this->afterCommit(); }
    public function via(object $notifiable): array { return ['mail']; }
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $recovery = StaffEmailRecovery::find($this->recoveryId);
        return $recovery && app(StaffEmailRecoveryService::class)->canDeliver($recovery, 'code');
    }
    public function toMail(object $notifiable): MailMessage
    {
        $expiry = \Illuminate\Support\Carbon::parse($this->expiresAt)->timezone('Asia/Manila')->format('g:i A');
        return (new MailMessage)->subject('Verify Staff Email Recovery - Bethlehem Animal Clinic')
            ->line('Use this code to authorize the staff email recovery you requested.')
            ->line('**'.$this->code.'**')->line("This code expires at {$expiry} (Philippine Time) and can only be used once.")
            ->line('If you did not request this recovery, contact the clinic administrator.');
    }
}
