<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerSecurityChange extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['code_hash', 'new_password_hash', 'account_fingerprint', 'delivery_receipt'];

    protected $casts = [
        'last_sent_at' => 'datetime',
        'code_expires_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
