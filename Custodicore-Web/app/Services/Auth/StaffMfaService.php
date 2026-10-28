<?php

namespace App\Services\Auth;

use App\Mail\StaffMfaCode;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Module;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Two-step verification for the staff web dashboards (Admin/Warden, Record
 * Officer, Front Desk Officer). Visitors on the mobile app are not involved.
 *
 * Sign-in becomes: email or username + password  ->  one more proof  ->  signed in.
 * The second proof is one of:
 *   - the 6-digit code from the officer's authenticator app (App\Support\Totp)
 *   - a 6-digit code emailed to them (fallback if the phone is not at hand)
 *   - one of 8 one-time backup codes (fallback if the phone is lost)
 *
 * Between the password and the second proof the officer is NOT signed in: the
 * session only remembers "this account passed the password step" (`mfa_pending`,
 * expires after config('mfa.pending_minutes')). The web session is created only
 * once the second proof is right (finishLogin).
 *
 * Secrets are stored encrypted, backup codes only as keyed hashes, used codes
 * cannot be replayed, and wrong codes are rate-limited. A ticked "trust this
 * device" box lets that browser skip the code for config('mfa.trusted_days')
 * days; the cookie stops working when the officer's MFA is reset or their
 * password changes.
 */
class StaffMfaService
{
    public const STAFF_ROLES = ['System Administrator/Warden', 'Record Officer', 'Front Desk Officer'];

    public const TRUST_COOKIE = 'cc_mfa_trust';

    // ---------------------------------------------------------------- who needs it

    public function required(): bool
    {
        return (bool) config('mfa.required', true);
    }

    public function appliesTo(Account $account): bool
    {
        return in_array($account->role?->role_name, self::STAFF_ROLES, true);
    }

    public function isEnabled(Account $account): bool
    {
        return $account->mfa_enabled_at !== null && $account->mfa_secret !== null;
    }

    /** Whether signing in this account must pass through the second step (set up or verify). */
    public function gateApplies(Account $account): bool
    {
        return $this->appliesTo($account) && ($this->required() || $this->isEnabled($account));
    }

    // ---------------------------------------------------------------- password step done

    public function startPending(Request $request, Account $account): void
    {
        // New session id: nothing from before the password step carries over (session fixation).
        $request->session()->regenerate();
        $request->session()->forget(['mfa_email', 'mfa_setup_secret', 'mfa_new_codes']);
        $request->session()->put('mfa_pending', ['id' => $account->getKey(), 'at' => time()]);
    }

    /** The account that passed the password step and is waiting for its code, if still valid. */
    public function pending(Request $request): ?Account
    {
        $pending = $request->session()->get('mfa_pending');

        if (! is_array($pending) || ! isset($pending['id'], $pending['at'])) {
            return null;
        }

        if (time() - (int) $pending['at'] > max(1, (int) config('mfa.pending_minutes', 10)) * 60) {
            $this->clearPending($request);

            return null;
        }

        $account = Account::with(['role', 'staffProfile'])->find($pending['id']);

        if (! $account || ! $this->appliesTo($account) || $account->status !== 'active' || $account->invitationState() !== null) {
            $this->clearPending($request);

            return null;
        }

        return $account;
    }

    public function clearPending(Request $request): void
    {
        $request->session()->forget(['mfa_pending', 'mfa_email', 'mfa_setup_secret']);
    }

    /** Signs the officer in for real (second step passed). */
    public function finishLogin(Request $request, Account $account): void
    {
        Auth::guard('web')->loginUsingId($account->getKey());

        $request->session()->regenerate();
        $this->clearPending($request);

        $account->forceFill(['last_login_at' => now()])->save();
    }

    // ---------------------------------------------------------------- setup / reset

    /** The secret being set up (kept in the session until the first code is confirmed). */
    public function setupSecret(Request $request): string
    {
        $secret = $request->session()->get('mfa_setup_secret');

        if (! is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            $request->session()->put('mfa_setup_secret', $secret);
        }

        return $secret;
    }

    public function otpUri(Account $account, string $secret): string
    {
        return Totp::uri($secret, $account->email, (string) config('mfa.issuer', 'CustodiCore'));
    }

    /**
     * Turns two-step verification on after the first code was confirmed.
     *
     * @return list<string> the backup codes, in plain text — shown once, never stored
     */
    public function enable(Account $account, string $secret, int $step): array
    {
        $account->forceFill([
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_enabled_at' => now(),
            'mfa_last_used_step' => $step,
        ])->save();

        return $this->generateBackupCodes($account);
    }

    /** Removes the officer's authenticator (lost phone). They set up again at their next sign-in. */
    public function reset(Account $account): void
    {
        $account->forceFill([
            'mfa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_last_used_step' => null,
            'mfa_backup_codes' => null,
        ])->save();
    }

    public function secret(Account $account): ?string
    {
        if ($account->mfa_secret === null) {
            return null;
        }

        try {
            return Crypt::decryptString($account->mfa_secret);
        } catch (\Throwable) {
            return null; // app key changed: treat as not set up rather than crash
        }
    }

    // ---------------------------------------------------------------- checking a code

    /** True when $input is the current authenticator code and has not been used before. */
    public function verifyTotp(Account $account, string $input): bool
    {
        $secret = $this->secret($account);
        if ($secret === null) {
            return false;
        }

        $last = $account->mfa_last_used_step !== null ? (int) $account->mfa_last_used_step : null;
        $step = Totp::verify($secret, $input, 1, $last);

        if ($step === null) {
            return false;
        }

        // Compare-and-set: of two requests carrying the same code, only one wins.
        return Account::whereKey($account->getKey())
            ->where(fn ($q) => $q->whereNull('mfa_last_used_step')->orWhere('mfa_last_used_step', '<', $step))
            ->update(['mfa_last_used_step' => $step]) > 0;
    }

    /** Backup codes look like ABCDE-FGHJK: ten letters/digits, dash optional. */
    public static function looksLikeBackupCode(string $input): bool
    {
        return strlen(self::normalizeBackupCode($input)) === 10;
    }

    /** True (and the code is used up) when $input is one of the officer's unused backup codes. */
    public function verifyBackupCode(Account $account, string $input): bool
    {
        $hash = $this->hashBackupCode($input);

        return DB::transaction(function () use ($account, $hash) {
            $locked = Account::whereKey($account->getKey())->lockForUpdate()->first();
            $hashes = $this->storedBackupHashes($locked);

            foreach ($hashes as $i => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($hashes[$i]);
                    $locked->forceFill(['mfa_backup_codes' => json_encode(array_values($hashes))])->save();

                    return true;
                }
            }

            return false;
        });
    }

    /** @return list<string> */
    public function generateBackupCodes(Account $account): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $plain = [];
        $hashes = [];

        for ($i = 0; $i < max(1, (int) config('mfa.backup_codes', 8)); $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($code, 0, 5) . '-' . substr($code, 5);
            $hashes[] = $this->hashBackupCode($code);
        }

        $account->forceFill(['mfa_backup_codes' => json_encode($hashes)])->save();

        return $plain;
    }

    public function backupCodesLeft(Account $account): int
    {
        return count($this->storedBackupHashes($account));
    }

    // ---------------------------------------------------------------- emailed code

    /**
     * Emails a 6-digit code to the officer (valid config('mfa.email_code_minutes') minutes).
     *
     * @return array{status: string, to: string, wait?: int, problem?: string}
     *         status: sent | log_only | failed | throttled
     */
    public function sendEmailCode(Request $request, Account $account): array
    {
        $to = $account->staffProfile?->personal_email ?: $account->email;
        $masked = self::maskEmail($to);

        $key = 'mfa-email-send:' . $account->getKey();
        $max = max(1, (int) config('mfa.email_max_sends', 3));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return ['status' => 'throttled', 'to' => $masked, 'wait' => RateLimiter::availableIn($key)];
        }
        RateLimiter::hit($key, 900);

        $minutes = max(1, (int) config('mfa.email_code_minutes', 10));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $request->session()->put('mfa_email', [
            'hash' => $this->hashCode($code),
            'exp' => time() + $minutes * 60,
        ]);

        try {
            Mail::to($to)->send(new StaffMfaCode($account, $code, $minutes));
        } catch (\Throwable $e) {
            // Never log the code itself.
            Log::warning('Two-step verification email could not be sent for account #' . $account->getKey()
                . ' (' . $e::class . '): ' . Str::limit(preg_replace('/\d{6}/', '[code]', $e->getMessage()), 200));

            return ['status' => 'failed', 'to' => $masked, 'problem' => 'the email could not be sent'];
        }

        return ['status' => StaffInvitationService::mailIsLogOnly() ? 'log_only' : 'sent', 'to' => $masked];
    }

    /** True when $input is the emailed code and it has not expired. The code is single-use. */
    public function verifyEmailCode(Request $request, string $input): bool
    {
        $stored = $request->session()->get('mfa_email');
        $input = preg_replace('/\s+/', '', $input) ?? '';

        if (! is_array($stored) || ! preg_match('/^\d{6}$/', $input)) {
            return false;
        }

        if (time() > (int) ($stored['exp'] ?? 0)) {
            $request->session()->forget('mfa_email');

            return false;
        }

        if (! hash_equals((string) $stored['hash'], $this->hashCode($input))) {
            return false;
        }

        $request->session()->forget('mfa_email');

        return true;
    }

    // ---------------------------------------------------------------- wrong-code limiter

    public function attemptsKey(Request $request, Account $account): string
    {
        return 'mfa-verify:' . sha1($account->getKey() . '|' . $request->ip());
    }

    public function tooManyAttempts(Request $request, Account $account): bool
    {
        return RateLimiter::tooManyAttempts($this->attemptsKey($request, $account), max(1, (int) config('mfa.max_attempts', 5)));
    }

    public function hitAttempts(Request $request, Account $account): void
    {
        RateLimiter::hit($this->attemptsKey($request, $account), max(60, (int) config('mfa.decay_seconds', 900)));
    }

    public function clearAttempts(Request $request, Account $account): void
    {
        RateLimiter::clear($this->attemptsKey($request, $account));
    }

    // ---------------------------------------------------------------- "trust this device"

    public function trustedDeviceValid(Request $request, Account $account): bool
    {
        if (! $this->isEnabled($account) || (int) config('mfa.trusted_days', 30) <= 0) {
            return false;
        }

        $parts = explode('.', (string) $request->cookie(self::TRUST_COOKIE), 3);
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return false;
        }

        [$id, $expires, $signature] = [(int) $parts[0], (int) $parts[1], $parts[2]];

        return $id === (int) $account->getKey()
            && $expires > time()
            && hash_equals($this->trustSignature($account, $expires), $signature);
    }

    public function trustDevice(Request $request, Account $account): void
    {
        $days = (int) config('mfa.trusted_days', 30);
        if ($days <= 0) {
            return;
        }

        $expires = time() + $days * 86400;

        Cookie::queue(Cookie::make(
            self::TRUST_COOKIE,
            $account->getKey() . '.' . $expires . '.' . $this->trustSignature($account, $expires),
            $days * 1440,
            '/',
            null,
            $request->isSecure(),
            true,   // httpOnly
            false,
            'lax'
        ));
    }

    /** Tied to the secret and the password hash, so resetting MFA or changing the password cancels every trusted device. */
    private function trustSignature(Account $account, int $expires): string
    {
        $fingerprint = hash('sha256', (string) $account->mfa_secret . '|' . (string) $account->password_hash);

        return hash_hmac('sha256', $account->getKey() . '|' . $expires . '|' . $fingerprint, (string) config('app.key'));
    }

    // ---------------------------------------------------------------- audit + helpers

    /**
     * Records a security event in the Audit Trail. `$byOfficer` credits the entry to the
     * officer themselves (they are not signed in yet during setup/sign-in); otherwise it is
     * credited to whoever is signed in (e.g. the Admin resetting someone's MFA).
     */
    public function audit(Account $subject, string $description, bool $byOfficer = true): void
    {
        $log = AuditLog::record('update', 'accounts', (int) $subject->getKey(), Str::limit($description, 250), Module::CODE_USER_MANAGEMENT);

        if ($byOfficer) {
            $log->account_id = $subject->getKey();
            $log->save();
        }
    }

    /** j***@gmail.com */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $domain === '' ? '***' : mb_substr($local, 0, 1) . '***@' . $domain;
    }

    public static function normalizeBackupCode(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
    }

    private function hashBackupCode(string $input): string
    {
        return $this->hashCode(self::normalizeBackupCode($input));
    }

    private function hashCode(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /** @return list<string> */
    private function storedBackupHashes(Account $account): array
    {
        $list = json_decode((string) $account->mfa_backup_codes, true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
