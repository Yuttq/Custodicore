<?php

namespace App\Services\Auth;

use App\Mail\VerifyVisitorEmail;
use App\Models\Account;
use App\Models\EmailVerificationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Visitor email verification (Phase 2): proves the visitor controls
 * accounts.email. Completely separate from staff review of the visitor's
 * information/documents (visitor_profiles.verification_status).
 *
 * Not Laravel's MustVerifyEmail: that relies on the Notifiable trait, which
 * Account deliberately does not use (see the doc comment on Account), and
 * its signed URLs are reusable until they expire. Here each link is:
 *  - random (64 chars) and stored only as a SHA-256 hash
 *  - bound to the account AND the address it was sent to
 *  - expiring (config auth.email_verification.expire, minutes)
 *  - single-use: consumed on success, and every older link of the account
 *    is deleted whenever a new one is issued
 *
 * The plain token only ever appears in the emailed URL — never logged,
 * stored, or returned in an API response. Verifying never issues a Sanctum
 * token or signs anyone in.
 */
class EmailVerificationService
{
    public const VERIFIED = 'verified';
    public const ALREADY_VERIFIED = 'already_verified';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';

    /** Creates a new link for $account (replacing older ones) and emails it. */
    public function send(Account $account): void
    {
        $token = $this->issue($account);

        try {
            Mail::to($account->email)->send(new VerifyVisitorEmail(
                $account,
                $this->verificationUrl($token),
                $this->expiresInMinutes(),
            ));
        } catch (\Throwable $e) {
            // The account is kept; the visitor can request another email.
            // Exception class only — the message may contain the link.
            Log::warning('Verification email could not be sent for account #'.$account->account_id.' ('.$e::class.')');
        }
    }

    /** @return string the plain token (only for building the emailed URL) */
    public function issue(Account $account): string
    {
        $token = Str::random(64);

        DB::transaction(function () use ($account, $token) {
            EmailVerificationToken::where('account_id', $account->account_id)->delete();

            EmailVerificationToken::create([
                'account_id' => $account->account_id,
                'email' => $account->email,
                'token_hash' => self::hash($token),
                'expires_at' => now()->addMinutes($this->expiresInMinutes()),
            ]);
        });

        return $token;
    }

    /**
     * Consumes a link. Returns one of the class constants. An unknown,
     * already-used or superseded token is INVALID (indistinguishable on
     * purpose); an expired one is deleted and reported as EXPIRED.
     */
    public function verify(string $token): string
    {
        if ($token === '' || strlen($token) > 255) {
            return self::INVALID;
        }

        return DB::transaction(function () use ($token) {
            $record = EmailVerificationToken::where('token_hash', self::hash($token))
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return self::INVALID;
            }

            if ($record->expires_at->isPast()) {
                $record->delete();

                return self::EXPIRED;
            }

            $account = Account::lockForUpdate()->find($record->account_id);

            // The address changed after the link was sent: it proves nothing now.
            if (! $account || mb_strtolower($account->email) !== mb_strtolower($record->email)) {
                $record->delete();

                return self::INVALID;
            }

            // Single use: this and any other outstanding link for the account.
            EmailVerificationToken::where('account_id', $account->account_id)->delete();

            if ($account->hasVerifiedEmail()) {
                return self::ALREADY_VERIFIED;
            }

            // forceFill: email_verified_at is deliberately not fillable.
            $account->forceFill(['email_verified_at' => now()])->save();

            return self::VERIFIED;
        });
    }

    /**
     * Resend for an email address. Silently does nothing unless it belongs
     * to an unverified Visitor account — the caller always answers the same
     * way, so this never reveals whether an address is registered.
     */
    public function resend(string $email): void
    {
        $account = Account::with('role')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();

        if (! $account || ! $account->isVisitor() || $account->hasVerifiedEmail() || $account->status !== 'active') {
            return;
        }

        $this->send($account);
    }

    /**
     * Built on APP_URL, not the current request's host: links sent during
     * API registration/resend must point to the address the recipient can
     * reach (e.g. http://10.0.2.2:8000 for the Android emulator).
     */
    public function verificationUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/')
            .route('email-verification.show', ['token' => $token], false);
    }

    public function expiresInMinutes(): int
    {
        return (int) config('auth.email_verification.expire', 1440);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
