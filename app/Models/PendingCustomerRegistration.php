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
        'registration_recovery_token_hash',
        'registration_recovery_expires_at',
    ];

    protected $hidden = [
        'password_hash',
        'email_verification_token',
        'email_verification_code_hash',
        'registration_edit_token_hash',
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
        return now()->subHours(max(1, (int) config('app.pending_registration_retention_hours', 48)));
    }

    public function retentionExpired(): bool
    {
        return ! $this->created_at || $this->created_at->lessThanOrEqualTo(self::retentionCutoff());
    }

    public static function pruneExpired(): int
    {
        // DELETE acquires the same row locks used by verification and edits.
        return self::query()->where('created_at', '<=', self::retentionCutoff())->orWhereNull('created_at')->delete();
    }
}
