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
     * @return array{email: bool, email_delivered: bool, email_problem: ?string, email_to: string, sms: string, url: string}
     *         `email` = the mailer accepted it; `email_delivered` = it really left for the officer's inbox
     *         (false in log mode, where nothing is sent). `sms` is SmsSender::SENT|LOGGED|FAILED or 'none'.
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

        // The officer's own inbox when one was recorded; older accounts fall back to the sign-in email.
        $sendTo = $this->recipientFor($account);

        $emailSent = true;
        $emailProblem = null;
        try {
            Mail::to($sendTo)->send(new StaffAccountInvitation($account, $url, $hours));
        } catch (\Throwable $e) {
            $emailSent = false;
            $emailProblem = self::explainMailFailure($e);

            // Logged without any link, so a log file never holds a usable setup link for a failed send.
            $detail = preg_replace('#https?://\S+#i', '[url]', Str::limit($e->getMessage(), 300));
            Log::warning('Staff setup email could not be sent for account #' . $account->account_id
                . ' (' . $e::class . '): ' . $detail);
        }

        // 'log' / 'array' mailers accept the message but never deliver it.
        $reallySent = $emailSent && ! self::mailIsLogOnly();

        $smsResult = 'none';
        $contact = $account->staffProfile?->contact_number;
        if ($contact) {
            $smsResult = $this->sms->send(
                $contact,
                "CustodiCore: {$account->displayName()}, your staff account is ready. "
                . "Set your own password here (valid {$hours}h, one use): {$url}"
            );
        }

        return [
            'email' => $emailSent,
            'email_delivered' => $reallySent,
            'email_problem' => $emailProblem,
            'email_to' => $sendTo,
            'sms' => $smsResult,
            'url' => $url,
        ];
    }

    /** Where the setup link is emailed: the personal email if recorded, otherwise the sign-in email. */
    public function recipientFor(Account $account): string
    {
        $account->loadMissing('staffProfile');

        return $account->staffProfile?->personal_email ?: $account->email;
    }

    /** True while MAIL_MAILER is log/array: no email account is connected, nothing leaves the server. */
    public static function mailIsLogOnly(): bool
    {
        return in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** A plain-English reason for a failed send. Never repeats the raw exception text. */
    private static function explainMailFailure(\Throwable $e): string
    {
        $m = strtolower($e->getMessage());

        return match (true) {
            str_contains($m, '535') || str_contains($m, 'authenticat') || str_contains($m, 'username and password')
                => 'the mail server rejected the login. Check MAIL_USERNAME and MAIL_PASSWORD (for Gmail, use an App Password)',
            str_contains($m, 'could not be established') || str_contains($m, 'timed out') || str_contains($m, 'getaddrinfo')
                || str_contains($m, 'connection refused') || str_contains($m, 'name or service')
                => 'the mail server could not be reached. Check MAIL_HOST, MAIL_PORT and the internet connection',
            str_contains($m, '550') || str_contains($m, '553') || str_contains($m, '554') || str_contains($m, 'recipient')
                => 'the mail server refused the recipient address',
            default => 'an unexpected mail error happened. Details are in storage/logs/laravel.log',
        };
    }

    /** Plain-English delivery summary for the Warden's confirmation message. */
    public static function describe(array $result): string
    {
        $to = $result['email_to'] ?? 'the officer';

        $email = match (true) {
            ! empty($result['email_delivered']) => "emailed to {$to}",
            ! empty($result['email']) => 'NOT emailed: no email account is connected yet (mail is in log mode), so copy the link below and give it to the officer',
            default => 'email FAILED: ' . ($result['email_problem'] ?? 'unknown problem') . '. Copy the link below and give it to the officer',
        };

        $sms = match ($result['sms']) {
            SmsSender::SENT => 'texted',
            SmsSender::LOGGED => 'SMS not sent (no SMS provider configured)',
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
