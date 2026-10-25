<?php

namespace App\Notifications;

use App\Models\StaffEmailChange;
use App\Services\StaffEmailChangeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class VerifyStaffEmailChangeNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    use Concerns\TracksAuthenticationMail;

    public int $tries = 3;

    public function __construct(public readonly string $code, public readonly string $stage, public readonly string $changeId, public readonly string $expiresAt)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array { return ['mail']; }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $change = StaffEmailChange::find($this->changeId);
        return $change && $change->stage === $this->stage && app(StaffEmailChangeService::class)->canDeliver($change);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $address = $this->stage === 'current' ? 'current' : 'new';
        $expiry = Carbon::parse($this->expiresAt)->timezone('Asia/Manila')->format('g:i A');
        return (new MailMessage)->subject('Verify your '.$address.' email - Bethlehem Animal Clinic & Grooming')
            ->line('Use this code to verify your '.$address.' email for your staff email change.')
            ->line('**'.$this->code.'**')
            ->line("This code expires at {$expiry} (Philippine Time) and can only be used once.")
            ->line('If you did not request this change, ignore this code and contact the clinic administrator.');
    }
}
