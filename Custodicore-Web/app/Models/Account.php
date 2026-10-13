<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The single login table for every role (System Administrator/Warden,
 * Record Officer, Front Desk Officer, Visitor) — the web dashboards AND
 * the mobile app both authenticate against this same table/model now.
 *
 * This is now the real Authenticatable used by config/auth.php (both the
 * 'web' session guard for the three staff dashboards and the 'sanctum'
 * bearer-token guard for the mobile app). App\Models\User (Laravel's
 * original scaffold model/migration) is left in place but unused — nothing
 * reads or writes it anymore.
 *
 * password_hash (not `password`) is the actual column name in the schema,
 * so getAuthPassword()/getAuthPasswordName() are overridden below.
 *
 * Deliberately does NOT use Laravel's `Notifiable` trait: this app already
 * has its own `notifications` table (see database/migrations/..._000210 and
 * App\Models\Notification) with a completely different shape than the one
 * Notifiable/DatabaseNotification expect (notification_id/account_id/
 * is_read/... vs. Laravel's uuid id/notifiable_type/data/read_at). Adding
 * Notifiable here would silently query the wrong schema — the exact kind
 * of name collision that already bit this project once with AuditLog vs.
 * App\Support\AuditLog. Use App\Models\Notification directly instead.
 */
class Account extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'accounts';
    protected $primaryKey = 'account_id';

    protected $fillable = [
        'role_id', 'username', 'email', 'password_hash', 'status',
        // Consent recorded at registration (see config/legal.php for the version).
        'terms_accepted_at', 'privacy_accepted_at', 'consent_version',
    ];

    // google_id (verified Google `sub`, see the 2026_10_05 migration) is
    // deliberately NOT fillable: it must only ever be set explicitly from a
    // server-verified Google ID token, never mass-assigned from request data.
    protected $hidden = ['password_hash', 'google_id', 'invitation_token_hash'];

    // invitation_* and password_set_at are deliberately NOT fillable either:
    // only App\Services\Auth\StaffInvitationService sets them.

    // email_verified_at is deliberately NOT fillable either: it is only set
    // by App\Services\Auth\EmailVerificationService (a used verification
    // link) or from a server-verified Google identity (GoogleAuthService).

    // last_login_at is a custom timestamp column (not created_at/updated_at),
    // so it must be cast or Admin\UserController's ->format() fails on a string.
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'password_set_at' => 'datetime',
        ];
    }

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    /**
     * Overrides the CanResetPassword trait (inherited from Authenticatable),
     * whose default calls $this->notify() — Account has no Notifiable (see
     * above), so that would fail. Sends the reset email directly instead.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        app(\App\Services\Auth\PasswordResetService::class)->sendLink($this, $token);
    }

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

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'account_id')->orderBy('sent_at', 'desc');
    }

    public function displayName(): string
    {
        return $this->staffProfile?->full_name
            ?? $this->visitorProfile?->full_name
            ?? $this->username;
    }

    /** e.g. $account->isRole('System Administrator/Warden') */
    public function isRole(string $roleName): bool
    {
        return $this->role?->role_name === $roleName;
    }

    /** The visitor has proven they control accounts.email (Phase 2). */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * A staff account whose owner has not opened the setup link yet:
     * 'pending' (link still valid), 'expired' (needs a new link) or null
     * (set up / no invitation). While this is not null the account cannot
     * sign in — its password is a random value nobody knows.
     */
    public function invitationState(): ?string
    {
        if ($this->invitation_token_hash === null) {
            return null;
        }

        return $this->invitation_expires_at !== null && $this->invitation_expires_at->isPast() ? 'expired' : 'pending';
    }

    /** Only Visitor accounts may authenticate through the mobile API. */
    public function isVisitor(): bool
    {
        return $this->isRole('Visitor');
    }
}
