<?php

namespace App\Services\Auth;

use App\Mail\StaffAccountInvitation;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Services\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * "Set up your own account" links for staff the Warden registers.
 *
 * The Warden never chooses or sees a password. A new account is created
 * `inactive` with a random password nobody knows; the officer receives a
 * link by email and SMS, opens it, and chooses their own password (and
 * confirms username / mobile number). Only then does the account become
 * `active`.
 *
 * Each link is random (64 chars), stored only as a SHA-256 hash, expires
 * (config auth.staff_invitation.expire_hours) and is single-use. Issuing a
 * new link replaces the old one. The plain token exists only in the
 * emailed / texted URL. The one exception: send() also returns the URL so the
 * Warden can hand it over in person when no email/SMS provider is set up; the
 * controller shows it once (session flash) and never stores it.
 */
class StaffInvitationService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';

    public function __construct(private SmsSender $sms) {}

    public function expireHours(): int
    {
        return max(1, (int) config('auth.staff_invitation.expire_hours', 72));
    }

    /** A random password nobody knows — placeholder until the officer sets theirs. */
    public static function unusablePasswordHash(): string
    {
        return Hash::make(Str::random(64));
    }

    /**
     * Issues a fresh link (replacing any older one) and delivers it.
     *
     * @return array{email: bool, sms: string, url: string} sms is SmsSender::SENT|LOGGED|FAILED or 'none' (no number on file)
     */
    public function send(Account $account): array
    {
        $account->loadMissing(['staffProfile', 'role']);

        $token = Str::random(64);

        $account->forceFill([
            'invitation_token_hash' => self::hash($token),
            'invitation_sent_at' => now(),
            'invitation_expires_at' => now()->addHours($this->expireHours()),
        ])->save();

        $url = route('staff-setup.show', ['token' => $token]);
        $hours = $this->expireHours();

        $emailSent = true;
        try {
            Mail::to($account->email)->send(new StaffAccountInvitation($account, $url, $hours));
        } catch (\Throwable $e) {
            // Exception class only — the message may contain the link.
            Log::warning('Staff setup email could not be sent for account #' . $account->account_id . ' (' . $e::class . ')');
            $emailSent = false;
        }

        $smsResult = 'none';
        $contact = $account->staffProfile?->contact_number;
        if ($contact) {
            $smsResult = $this->sms->send(
                $contact,
                "CustodiCore: {$account->displayName()}, your staff account is ready. "
                . "Set your own password here (valid {$hours}h, one use): {$url}"
            );
        }

        return ['email' => $emailSent, 'sms' => $smsResult, 'url' => $url];
    }

    /** Plain-English delivery summary for the Warden's confirmation message. */
    public static function describe(array $result): string
    {
        $email = $result['email'] ? 'emailed' : 'email FAILED';
        $sms = match ($result['sms']) {
            SmsSender::SENT => 'texted',
            SmsSender::LOGGED => 'SMS written to the log only (no SMS provider configured)',
            SmsSender::FAILED => 'SMS FAILED',
            default => 'no mobile number on file',
        };

        return "Setup link: {$email}; {$sms}.";
    }

    /** @return array{0: string, 1: ?Account} [state, account] */
    public function find(string $token): array
    {
        if ($token === '' || strlen($token) > 255) {
            return [self::INVALID, null];
        }

        $account = Account::with(['staffProfile', 'role'])
            ->where('invitation_token_hash', self::hash($token))
            ->first();

        if (! $account || $account->status !== 'inactive') {
            return [self::INVALID, null];
        }

        if ($account->invitation_expires_at === null || $account->invitation_expires_at->isPast()) {
            return [self::EXPIRED, $account];
        }

        return [self::OK, $account];
    }

    /**
     * Consumes the link: sets the officer's own password, username and
     * mobile number, activates the account and marks the email as verified
     * (they received the link there). Returns false if the link was already
     * used, expired or replaced in the meantime.
     */
    public function complete(string $token, string $password, string $username, string $contactNumber): bool
    {
        return DB::transaction(function () use ($token, $password, $username, $contactNumber) {
            $account = Account::with('staffProfile')
                ->where('invitation_token_hash', self::hash($token))
                ->lockForUpdate()
                ->first();

            if (! $account || $account->status !== 'inactive'
                || $account->invitation_expires_at === null || $account->invitation_expires_at->isPast()) {
                return false;
            }

            $account->forceFill([
                'password_hash' => Hash::make($password),
                'username' => $username,
                'status' => 'active',
                'email_verified_at' => $account->email_verified_at ?? now(),
                'password_set_at' => now(),
                'invitation_token_hash' => null,
                'invitation_sent_at' => null,
                'invitation_expires_at' => null,
            ])->save();

            $account->staffProfile?->update(['contact_number' => $contactNumber]);

            // The officer is not signed in yet, so credit the entry to them explicitly.
            $log = AuditLog::record(
                'update',
                'accounts',
                $account->account_id,
                Str::limit("{$account->displayName()} completed account setup and chose their own password", 250),
                Module::CODE_USER_MANAGEMENT
            );
            $log->account_id = $account->account_id;
            $log->save();

            return true;
        });
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
