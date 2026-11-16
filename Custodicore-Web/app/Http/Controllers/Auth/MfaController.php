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

    /** GET login/mfa — sends the emailed code (once) and asks for it. */
    public function challenge(Request $request): Response|RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        if (! $this->mfa->isEnabled($account)) {
            return redirect()->route('mfa.setup');
        }

        return $this->noStore(view('auth.mfa-challenge', [
            'account' => $account,
            'emailTo' => StaffMfaService::maskEmail($this->mfa->emailTarget($account)),
            'sendError' => $this->sendIfNeeded($request, $account),
        ]));
    }

    /** POST login/mfa {code} */
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
            'code.required' => 'Enter the 6-digit code we emailed you.',
        ])['code'];

        if ($this->mfa->tooManyAttempts($request, $account)) {
            $this->mfa->clearPending($request);

            return $this->backToLogin('Too many wrong codes. Please wait a few minutes and sign in again.');
        }

        $digits = preg_replace('/\s+/', '', $input) ?? '';
        $ok = false;

        if (preg_match('/^\d{6}$/', $digits)) {
            // The emailed code; accounts set up earlier with an app may still use it.
            $ok = $this->mfa->verifyEmailCode($request, $digits)
                || ($account->mfa_secret !== null && $this->mfa->verifyTotp($account, $digits));
        } elseif (StaffMfaService::looksLikeBackupCode($input)) {
            $ok = $this->mfa->verifyBackupCode($account, $input);
            if ($ok) {
                $this->mfa->audit($account, 'Signed in with a two-step backup code.');
            }
        }

        if (! $ok) {
            $this->mfa->hitAttempts($request, $account);

            return back()->withErrors(['code' => 'That code is not right or has expired. Use the code in the newest email, or tap "Send a new code".']);
        }

        $this->mfa->clearAttempts($request, $account);

        if ($request->boolean('trust_device')) {
            $this->mfa->trustDevice($request, $account);
        }

        $this->mfa->finishLogin($request, $account);

        return redirect()->intended($this->homeFor($account));
    }

    /** POST login/mfa/email — "Send a new code" on the sign-in or setup page. */
    public function emailCode(Request $request): RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        $result = $this->mfa->sendEmailCode($request, $account);

        return ($problem = $this->emailProblem($result))
            ? back()->withErrors(['code' => $problem])
            : back()->with('status', "We emailed a new 6-digit code to {$result['to']}. It works for " . config('mfa.email_code_minutes') . ' minutes.');
    }

    /** GET login/mfa/cancel */
    public function cancel(Request $request): RedirectResponse
    {
        $this->mfa->clearPending($request);

        return redirect()->route('login');
    }

    // ---------------------------------------------------------------- first-time setup

    /** GET login/mfa/setup — first sign-in: email a code and ask for it to turn two-step verification on. */
    public function setup(Request $request): Response|RedirectResponse
    {
        $account = $this->mfa->pending($request);

        if (! $account) {
            return $this->backToLogin('Your sign-in took too long. Please sign in again.');
        }

        if ($this->mfa->isEnabled($account)) {
            return redirect()->route('mfa.challenge');
        }

        return $this->noStore(view('auth.mfa-setup', [
            'account' => $account,
            'emailTo' => StaffMfaService::maskEmail($this->mfa->emailTarget($account)),
            'sendError' => $this->sendIfNeeded($request, $account),
        ]));
    }

    /** POST login/mfa/setup {code} */
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
            'code.required' => 'Enter the 6-digit code we emailed you.',
        ])['code'];

        if ($this->mfa->tooManyAttempts($request, $account)) {
            $this->mfa->clearPending($request);

            return $this->backToLogin('Too many wrong codes. Please wait a few minutes and sign in again.');
        }

        if (! $this->mfa->verifyEmailCode($request, preg_replace('/\s+/', '', $input) ?? '')) {
            $this->mfa->hitAttempts($request, $account);

            return back()->withErrors(['code' => 'That code is not right or has expired. Use the code in the newest email, or tap "Send a new code".']);
        }

        $this->mfa->clearAttempts($request, $account);
        $this->mfa->enableEmail($account);

        if ($request->boolean('trust_device')) {
            $this->mfa->trustDevice($request, $account);
        }

        $this->mfa->finishLogin($request, $account);
        $this->mfa->audit($account, 'Two-step verification turned on (emailed codes).');

        return redirect()->intended($this->homeFor($account))
            ->with('status', 'Two-step verification is on. Each time you sign in on a new browser, we will email you a 6-digit code.');
    }

    /**
     * Emails a code when the page opens, unless one is already waiting (a refresh
     * does not send another) or the page is showing a wrong-code error.
     * Returns what went wrong, or null.
     */
    private function sendIfNeeded(Request $request, $account): ?string
    {
        if ($this->mfa->hasLiveEmailCode($request) || session()->has('errors')) {
            return null;
        }

        return $this->emailProblem($this->mfa->sendEmailCode($request, $account));
    }

    /** Null when the email went out; otherwise what to tell the officer. */
    private function emailProblem(array $result): ?string
    {
        return match ($result['status']) {
            'sent' => null,
            'log_only' => "Email is not set up on this server yet, so no message reached {$result['to']}. The code was only written to the application log. Ask the Admin.",
            'throttled' => 'Too many codes were requested. Try again in ' . max(1, (int) ceil(($result['wait'] ?? 60) / 60)) . ' minute(s).',
            default => "We could not send the email to {$result['to']}. Try again in a minute, or ask the Admin.",
        };
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
