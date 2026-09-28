<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'accounts';
    protected $primaryKey = 'account_id';

    protected $fillable = ['role_id', 'username', 'email', 'password_hash', 'status'];

    protected $hidden = ['password_hash'];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function staffProfile()
    {
        return $this->hasOne(StaffProfile::class, 'account_id');
    }

    public function visitorProfile()
    {
        return $this->hasOne(VisitorProfile::class, 'account_id');
    }

    /**
     * accounts has no name column of its own — display name lives on
     * whichever profile this account actually has (staff or visitor).
     * Falls back to username if somehow neither exists.
     */
    public function displayName(): string
    {
        return $this->staffProfile?->full_name
            ?? $this->visitorProfile?->full_name
            ?? $this->username;
    }
}
