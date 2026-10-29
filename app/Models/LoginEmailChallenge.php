<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginEmailChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'poll_token_hash',
        'remember_me',
        'expires_at',
        'approved_at',
        'failed_attempts',
        'source_hash',
        'last_sent_at',
        'delivery_receipt',
    ];

    protected $hidden = [
        'token_hash',
        'poll_token_hash',
        'source_hash',
        'delivery_receipt',
    ];

    protected $casts = [
        'remember_me' => 'boolean',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'failed_attempts' => 'integer',
        'last_sent_at' => 'datetime',
        'delivery_receipt' => 'encrypted',
    ];

    public static function pruneExpired(int $limit = 200): int
    {
        $ids = static::where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->pluck('id');
        return static::whereKey($ids)->delete();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
