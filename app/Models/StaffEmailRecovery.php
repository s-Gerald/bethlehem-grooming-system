<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffEmailRecovery extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['code_hash', 'token_hash', 'account_fingerprint', 'admin_fingerprint', 'session_token_id'];
    protected $casts = [
        'identity_verified_at' => 'datetime', 'code_expires_at' => 'datetime', 'expires_at' => 'datetime',
        'last_sent_at' => 'datetime', 'authorized_at' => 'datetime', 'resolved_at' => 'datetime',
        'expiration_recorded_at' => 'datetime', 'admin_attempts' => 'integer', 'failed_attempts' => 'integer',
    ];
}
