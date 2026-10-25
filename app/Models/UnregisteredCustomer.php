<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class UnregisteredCustomer extends Model
{
    use \App\Models\Concerns\RecordsActivityAtomically;

    protected $fillable = [
        'first_name',
        'last_name',
        'middle_name',
        'phone',
        'email',
        'created_by_user_id',
        'is_archived',
        'archived_at',
        'account_deleted_at',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
        'account_deleted_at' => 'datetime',
    ];

    public function scopeAvailableCustomer(Builder $query): Builder
    {
        if (Schema::hasColumn($this->getTable(), 'account_deleted_at')) {
            $query->whereNull('account_deleted_at');
        }

        return $query;
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function pets()
    {
        return $this->hasMany(Pet::class, 'unregistered_customer_id');
    }

    public function walkins()
    {
        return $this->hasMany(Walkin::class, 'unregistered_customer_id');
    }
}
