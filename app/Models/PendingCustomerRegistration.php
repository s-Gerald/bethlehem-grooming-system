<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class PendingCustomerRegistration extends Model
{
    use Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'phone',
        'email',
        'password_hash',
        'email_verification_token',
        'email_verification_expires_at',
        'email_verification_code_hash',
        'email_verification_attempts',
        'email_verification_last_sent_at',
        'registration_edit_token_hash',
        'registration_browser_token_hash',
        'registration_recovery_token_hash',
        'registration_recovery_expires_at',
    ];

    protected $hidden = [
        'password_hash',
        'email_verification_token',
        'email_verification_code_hash',
        'registration_edit_token_hash',
        'registration_browser_token_hash',
        'registration_recovery_token_hash',
    ];

    protected $casts = [
        'email_verification_expires_at' => 'datetime',
        'email_verification_last_sent_at' => 'datetime',
        'email_verification_attempts' => 'integer',
        'registration_recovery_expires_at' => 'datetime',
    ];

    public function scopeUnexpired($query)
    {
        return $query->where('created_at', '>', self::retentionCutoff());
    }

    public static function retentionCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subHour();
    }

    public function retentionExpiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->created_at?->copy()->addHour();
    }

    // Only email-proven review may outlive retention, for at most ten minutes.
    public function scopeWithActiveReview($query)
    {
        return $query->whereNotNull('created_at')->where('created_at', '>', now()->subMinutes(70))
            ->whereNotNull('registration_recovery_token_hash')->whereNotNull('registration_recovery_expires_at')
            ->where('registration_recovery_expires_at', '>', now());
    }

    public function scopeRetained($query)
    {
        return $query->where(fn ($query) => $query->unexpired()->orWhere(fn ($query) => $query->withActiveReview()));
    }

    public function hasActiveReview(): bool
    {
        return $this->registration_recovery_token_hash && $this->registration_recovery_expires_at?->isFuture()
            && $this->retentionExpiresAt()?->addMinutes(10)->isFuture();
    }

    public function retentionExpired(): bool
    {
        return ! $this->created_at || $this->created_at->lessThanOrEqualTo(self::retentionCutoff());
    }

    public static function pruneExpired(): int
    {
        // DELETE acquires the same row locks used by verification and edits.
        return self::query()->where(fn ($query) => $query->where('created_at', '<=', self::retentionCutoff())
            ->orWhereNull('created_at'))->whereNot(fn ($query) => $query->withActiveReview())->delete();
    }
}
