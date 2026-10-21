<?php

namespace App\Services\Auth;

use App\Mail\ResetAccountPassword;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use App\Support\VisitorPasswordPolicy;

/**
 * Visitor "Forgot Password": emailed reset link → web form → new password.
 *
 * Built on Laravel's password broker ('accounts' in config/auth.php):
 * tokens live in password_reset_tokens, stored hashed, expire after
 * auth.passwords.accounts.expire minutes, one per address (a new request
 * replaces the old link) and are deleted once used. The broker's own
 * notification path is NOT used: it needs the Notifiable trait, which
 * Account deliberately does not have (see the doc comment on Account), so
 * Account::sendPasswordResetNotification() calls sendLink() here instead.
 *
 * Who may reset: only an active Visitor account that is not waiting on a
 * staff setup invitation (isEligible()). Staff passwords stay a Warden
 * task ("Contact your Warden administrator" on the web login page). A
 * Google-linked visitor is eligible — every visitor has a password (Google
 * registration requires one) and resetting it leaves google_id untouched.
 *
 * Resetting does NOT set email_verified_at. Email verification has its own
 * single-use flow (EmailVerificationService); an unverified visitor who
 * resets a password still has to verify before logging in. This keeps the
 * two policies separate rather than silently merging them.
 *
 * The plain token only ever appears in the emailed URL — never logged,
 * stored unhashed, or returned in an API response.
 */
class PasswordResetService
{
    public const RESET = 'reset';
    public const INVALID = 'invalid';

    /** Visitors only, active, and not a staff invitation waiting to be used. */
    public static function isEligible(?Account $account): bool
    {
        return $account !== null
            && $account->isVisitor()
            && $account->status === 'active'
            && $account->invitation_token_hash === null;
    }

    /**
     * The visitor password policy (VisitorPasswordPolicy — the same one
     * registration uses), confirmed, plus: not the account's email address.
     */
    public static function passwordRules(?string $email = null): array
    {
        return VisitorPasswordPolicy::rules($email);
    }

    /**
     * Sends a reset link if $email belongs to an eligible account; otherwise
     * does nothing. The caller always answers the same way.
     *
     * Keeping the answer time alike for every address (reduces, does not
     * remove, timing differences):
     *  - every address goes through the broker and its timebox;
     *  - the email is sent in a defer() callback, which Laravel runs after
     *    sending the response. Whether the client actually receives the
     *    response before the SMTP round trip finishes depends on the server
     *    finishing the request early: PHP-FPM (fastcgi_finish_request) or
     *    LiteSpeed do; `php artisan serve` and Apache's mod_php (XAMPP) have
     *    neither and can keep the connection open until the email is sent,
     *    so there the timing gap may remain. Still synchronous in this process — never queued, so
     *    the link never sits in the jobs table — and a failed send is logged
     *    (sendLink());
     *  - when the broker hashed no token (unknown address, or one that got a
     *    link < 60 s ago), an equivalent hash is computed anyway, so known
     *    addresses aren't told apart by the bcrypt cost.
     */
    public function request(string $email): void
    {
        $account = $this->findByEmail($email);

        $status = $this->broker()->sendResetLink(
            ['email' => $account?->email ?? trim($email)],
            function (Account $account, #[\SensitiveParameter] string $token) {
                if (! self::isEligible($account)) {
                    // The broker created a token before asking us: drop it.
                    $this->broker()->deleteToken($account);

                    return 'ineligible';
                }

                defer(fn () => $account->sendPasswordResetNotification($token));

                return PasswordBroker::RESET_LINK_SENT;
            }
        );

        if ($status === PasswordBroker::INVALID_USER || $status === PasswordBroker::RESET_THROTTLED) {
            Hash::make(Str::random(64));
        }
    }

    /** Emails the reset link. Called via Account::sendPasswordResetNotification(). */
    public function sendLink(Account $account, #[\SensitiveParameter] string $token): void
    {
        try {
            Mail::to($account->email)->send(new ResetAccountPassword(
                $account,
                $this->resetUrl($account, $token),
                $this->expiresInMinutes(),
            ));
        } catch (\Throwable $e) {
            // The response has already gone out, so the failure is logged
            // for staff and the visitor can request another link. Exception
            // class only — the message may contain the link.
            Log::warning('Password reset email could not be sent for account #'.$account->account_id.' ('.$e::class.')');
        }
    }

    /** Whether the link still works (used to show the form or an error page up front). */
    public function tokenIsValid(string $email, #[\SensitiveParameter] string $token): bool
    {
        $account = $this->findByEmail($email);

        return self::isEligible($account) && $token !== '' && $this->broker()->tokenExists($account, $token);
    }

    /**
     * Sets the new password. Returns RESET, or INVALID for an unknown,
     * ineligible, expired, used or replaced link (indistinguishable on
     * purpose). On success the token is deleted by the broker and every
     * Sanctum token of the account is revoked (signs out the mobile app).
     */
    public function reset(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): string
    {
        $account = $this->findByEmail($email);

        if (! self::isEligible($account)) {
            return self::INVALID;
        }

        try {
            $status = $this->broker()->reset(
                ['email' => $account->email, 'token' => $token, 'password' => $password],
                function (Account $account, #[\SensitiveParameter] string $password) {
                    DB::transaction(function () use ($account, $password) {
                        $locked = Account::with('role')->lockForUpdate()->find($account->account_id);

                        // Re-checked under the lock: the account may have been
                        // deactivated (or changed role) since the link was sent.
                        if (! self::isEligible($locked)) {
                            throw new PasswordResetRefusedException('Account is no longer eligible for a password reset.');
                        }

                        $locked->forceFill(['password_hash' => Hash::make($password)])->save();
                        $locked->tokens()->delete();

                        // The visitor is not signed in, so credit the entry to them explicitly.
                        $log = AuditLog::record(
                            'update',
                            'accounts',
                            $locked->account_id,
                            Str::limit("{$locked->displayName()} reset their password using an emailed reset link", 250),
                            Module::CODE_USER_MANAGEMENT
                        );
                        $log->account_id = $locked->account_id;
                        $log->save();
                    });
                }
            );
        } catch (PasswordResetRefusedException) {
            $this->broker()->deleteToken($account);

            return self::INVALID;
        }

        return $status === PasswordBroker::PASSWORD_RESET ? self::RESET : self::INVALID;
    }

    /**
     * Built on APP_URL, not the current request's host: the request comes
     * from the app's API base, the link must point to an address the
     * recipient can open (same as EmailVerificationService).
     */
    public function resetUrl(Account $account, #[\SensitiveParameter] string $token): string
    {
        return rtrim((string) config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $account->email], false);
    }

    public function expiresInMinutes(): int
    {
        return (int) config('auth.passwords.accounts.expire', 60);
    }

    private function findByEmail(string $email): ?Account
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        return Account::with('role')->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private function broker(): PasswordBroker
    {
        return Password::broker('accounts');
    }
}
