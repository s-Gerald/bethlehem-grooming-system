<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class PendingCustomerRegistration extends Model
{
    use Notifiable;

    protected $fillable = [
        'email', 'registration_edit_token_hash', 'registration_browser_token_hash',
        'email_verification_token', 'email_verification_expires_at', 'email_verification_last_sent_at',
        'registration_approved_at', 'registration_completed_at', 'completed_session_id',
    ];

    protected $hidden = ['registration_edit_token_hash', 'registration_browser_token_hash', 'email_verification_token'];

    protected $casts = [
        'email_verification_expires_at' => 'datetime', 'email_verification_last_sent_at' => 'datetime',
        'registration_approved_at' => 'datetime', 'registration_completed_at' => 'datetime',
    ];

    public static function retentionCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subHour();
    }

    public function retentionExpiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->created_at?->copy()->addHour();
    }

    public function retentionExpired(): bool
    {
        return ! $this->created_at || $this->created_at->lessThanOrEqualTo(self::retentionCutoff());
    }

    public static function pruneExpired(): int
    {
        return self::query()->where(fn ($query) => $query->where('created_at', '<=', self::retentionCutoff())
            ->orWhereNull('created_at'))->delete();
    }
}
