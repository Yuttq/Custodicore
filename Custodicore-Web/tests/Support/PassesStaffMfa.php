<?php

namespace Tests\Support;

use App\Models\Account;
use App\Services\Auth\StaffMfaService;
use App\Support\Totp;
use Illuminate\Testing\TestResponse;

/**
 * Staff two-step verification in feature tests: turn it on for an account
 * with a known secret, then answer the challenge the way the officer's
 * authenticator app would.
 */
trait PassesStaffMfa
{
    /** @var array<int, string> account id => authenticator secret */
    private array $mfaSecrets = [];

    /** Sets up two-step verification for $account. @return string the secret */
    protected function enrollStaffMfa(Account $account): string
    {
        $secret = Totp::generateSecret();
        // The last used step sits two steps back, so every code in the current window is still unused.
        app(StaffMfaService::class)->enable($account, $secret, Totp::stepAt() - 2);
        $this->mfaSecrets[$account->getKey()] = $secret;

        return $secret;
    }

    /** The next unused code the app would show for $account (codes cannot be replayed). */
    protected function nextTotpCode(Account $account): string
    {
        $last = $account->fresh()->mfa_last_used_step;
        // From the current step (still inside the ±1 window if a 30-second boundary passes mid-request).
        $step = max(Totp::stepAt(), $last === null ? PHP_INT_MIN : (int) $last + 1);

        return Totp::code($this->mfaSecrets[$account->getKey()], $step);
    }

    /** Answers the pending sign-in's challenge with a valid authenticator code. */
    protected function passMfaChallenge(Account $account, array $extra = []): TestResponse
    {
        return $this->post(route('mfa.verify'), ['code' => $this->nextTotpCode($account)] + $extra);
    }

    /** The password step passed: the session waits for the code and nobody is signed in yet. */
    protected function assertWaitingForMfa(Account $account): void
    {
        $this->assertGuest('web');
        $this->assertSame($account->getKey(), session('mfa_pending.id'));
    }
}
