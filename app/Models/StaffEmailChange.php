<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffEmailChange extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['code_hash', 'account_fingerprint', 'session_token_id'];
    protected $casts = [
        'code_expires_at' => 'datetime', 'expires_at' => 'datetime',
        'last_sent_at' => 'datetime', 'cancelled_at' => 'datetime',
        'failed_attempts' => 'integer', 'session_token_id' => 'integer',
    ];
}
