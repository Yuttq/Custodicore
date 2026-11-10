<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Auth\StaffMfaService;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Second step of staff web sign-in (Admin/Warden, Record Officer, Front Desk Officer).
 *
 * LoginController checks the password, then parks the officer here with
 * `mfa_pending` in the session — no web session exists until the second proof
 * is right. See App\Services\Auth\StaffMfaService for the rules.
 *
 * Routes (routes/web.php):
 *   guest:  login/mfa (challenge, verify, email), login/mfa/setup (+confirm), login/mfa/cancel
 *   auth:   account/mfa/backup-codes (shown once), account/mfa/continue, account/mfa/regenerate
 */
class MfaController extends Controller
{
    public function __construct(private readonly StaffMfaService $mfa) {}

    // ---------------------------------------------------------------- sign-in challenge

    /** GET login/mfa */
    public function challenge(Request $request): Response|RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        if (! $this->mfa->isEnabled($account)) {
            return redirect()->route('mfa.setup');
        }

        return $this->noStore(view('auth.mfa-challenge', ['account' => $account]));
    }

    /** POST login/mfa */
    public function verify(Request $request): RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        if (! $this->mfa->isEnabled($account)) {
            return redirect()->route('mfa.setup');
        }

        $input = (string) $request->validate(['code' => ['required', 'string', 'max:32']], [
            'code.required' => 'Enter the code from your authenticator app.',
        ])['code'];

        if ($this->mfa->tooManyAttempts($request, $account)) {
            $this->mfa->clearPending($request);

            return $this->backToLogin('Too many wrong codes. Please wait a few minutes and sign in again.');
        }

        $digits = preg_replace('/\s+/', '', $input) ?? '';
        $ok = false;
        $method = 'authenticator app';

        if (preg_match('/^\d{6}$/', $digits)) {
            // Either the authenticator code or the emailed one — both are 6 digits.
            if ($this->mfa->verifyTotp($account, $digits)) {
                $ok = true;
            } elseif ($this->mfa->verifyEmailCode($request, $digits)) {
                $ok = true;
                $method = 'emailed code';
            }
        } elseif (StaffMfaService::looksLikeBackupCode($input)) {
            if ($this->mfa->verifyBackupCode($account, $input)) {
                $ok = true;
                $method = 'backup code';
            }
        }

        if (! $ok) {
            $this->mfa->hitAttempts($request, $account);

            return back()->withErrors(['code' => 'That code is not right or has expired. Try the newest code, or use a backup code.']);
        }

        $this->mfa->clearAttempts($request, $account);

        if ($request->boolean('trust_device')) {
            $this->mfa->trustDevice($request, $account);
        }

        $left = $method === 'backup code' ? $this->mfa->backupCodesLeft($account->fresh()) : null;
        $this->mfa->finishLogin($request, $account);

        if ($method === 'backup code') {
            $this->mfa->audit($account, 'Signed in with a two-step backup code (' . $left . ' left).');
        }

        $redirect = redirect()->intended($this->homeFor($account));

        return $left !== null && $left <= 2
            ? $redirect->with('status', $left === 0
                ? 'That was your last backup code. Make new ones on My Profile.'
                : "You have {$left} backup " . ($left === 1 ? 'code' : 'codes') . ' left. You can make new ones on My Profile.')
            : $redirect;
    }

    /** POST login/mfa/email */
    public function emailCode(Request $request): RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account || ! $this->mfa->isEnabled($account)) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        $result = $this->mfa->sendEmailCode($request, $account);
        $to = $result['to'];

        return match ($result['status']) {
            'sent' => back()->with('status', "We emailed a 6-digit code to {$to}. It works for " . config('mfa.email_code_minutes') . ' minutes.'),
            'log_only' => back()->with('status', "Email is not set up on this server yet, so no message reached {$to}. The code was only written to the application log. Use your authenticator app or a backup code, or ask the Admin."),
            'throttled' => back()->withErrors(['code' => 'Too many emailed codes were requested. Try again in ' . max(1, (int) ceil(($result['wait'] ?? 60) / 60)) . ' minute(s), or use your authenticator app or a backup code.']),
            default => back()->withErrors(['code' => "We could not send the email to {$to}. Use your authenticator app or a backup code, or ask the Admin."]),
        };
    }

    /** GET login/mfa/cancel */
    public function cancel(Request $request): RedirectResponse
    {
        $this->mfa->clearPending($request);

        return redirect()->route('login');
    }

    // ---------------------------------------------------------------- first-time setup

    /** GET login/mfa/setup */
    public function setup(Request $request): Response|RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        if ($this->mfa->isEnabled($account)) {
            return redirect()->route('mfa.challenge');
        }

        $secret = $this->mfa->setupSecret($request);

        return $this->noStore(view('auth.mfa-setup', [
            'account' => $account,
            'otpUri' => $this->mfa->otpUri($account, $secret),
            'groupedSecret' => Totp::groupSecret($secret),
        ]));
    }

    /** POST login/mfa/setup */
    public function setupConfirm(Request $request): RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        if ($this->mfa->isEnabled($account)) {
            return redirect()->route('mfa.challenge');
        }

        $input = (string) $request->validate(['code' => ['required', 'string', 'max:10']], [
            'code.required' => 'Enter the 6-digit code your app shows.',
        ])['code'];

        if ($this->mfa->tooManyAttempts($request, $account)) {
            $this->mfa->clearPending($request);

            return $this->backToLogin('Too many wrong codes. Please wait a few minutes and sign in again.');
        }

        $secret = $this->mfa->setupSecret($request);
        $step = Totp::verify($secret, preg_replace('/\s+/', '', $input) ?? '');

        if ($step === null) {
            $this->mfa->hitAttempts($request, $account);

            return back()->withErrors(['code' => 'That code is not right. Check the app is set to CustodiCore and try the newest code.']);
        }

        $this->mfa->clearAttempts($request, $account);

        $codes = $this->mfa->enable($account, $secret, $step);
        $this->mfa->finishLogin($request, $account);
        $this->mfa->audit($account, 'Two-step verification turned on.');

        $request->session()->flash('mfa_new_codes', $codes);

        return redirect()->route('account.mfa.backup-codes');
    }

    // ---------------------------------------------------------------- backup codes (signed in)

    /** GET account/mfa/backup-codes — the codes are shown once, then gone. */
    public function backupCodes(Request $request): Response|RedirectResponse
    {
        $codes = $request->session()->pull('mfa_new_codes');

        if (! is_array($codes) || $codes === []) {
            return redirect()->route('account.profile');
        }

        return $this->noStore(view('auth.mfa-backup-codes', [
            'account' => $request->user(),
            'codes' => $codes,
            'firstTime' => (bool) $request->session()->pull('mfa_first_time', true),
        ]));
    }

    /** GET account/mfa/continue */
    public function continue(Request $request): RedirectResponse
    {
        return redirect()->intended($this->homeFor($request->user()));
    }

    /** POST account/mfa/regenerate (password re-entered, see `reauth`) */
    public function regenerate(Request $request): RedirectResponse
    {
        $account = $request->user();

        if (! $this->mfa->isEnabled($account)) {
            return redirect()->route('account.profile');
        }

        $codes = $this->mfa->generateBackupCodes($account);
        $this->mfa->audit($account, 'Two-step backup codes regenerated.');

        $request->session()->flash('mfa_new_codes', $codes);
        $request->session()->put('mfa_first_time', false);

        return redirect()->route('account.mfa.backup-codes');
    }

    // ---------------------------------------------------------------- helpers

    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function homeFor(Account $account): string
    {
        return match ($account->role?->role_name) {
            'System Administrator/Warden' => route('admin.dashboard'),
            'Record Officer' => route('dashboard'),
            'Front Desk Officer' => route('frontdesk.dashboard'),
            default => route('login'),
        };
    }

    private function noStore(View $view): Response
    {
        return response($view)->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
