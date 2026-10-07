<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One outstanding email verification link (hash only). Managed exclusively
 * by App\Services\Auth\EmailVerificationService.
 */
class EmailVerificationToken extends Model
{
    protected $table = 'email_verification_tokens';
    public $timestamps = false; // created_at only, set by the database default

    protected $fillable = ['account_id', 'email', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
