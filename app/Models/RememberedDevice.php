<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RememberedDevice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
