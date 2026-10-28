<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Auth\StaffMfaService;
use Illuminate\Console\Command;

/**
 * php artisan cc:mfa-reset admin@example.com
 *
 * Safety net for an officer locked out of two-step verification when no other
 * Admin can use "Reset MFA" (for example the only Admin lost their phone AND
 * their backup codes). Needs shell access to the server, which is the point:
 * it cannot be triggered from the website.
 */
class MfaReset extends Command
{
    protected $signature = 'cc:mfa-reset {login : The officer\'s sign-in email or username}';

    protected $description = 'Remove an officer\'s two-step verification so they can set it up again at next sign-in';

    public function handle(StaffMfaService $mfa): int
    {
        $login = trim((string) $this->argument('login'));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $account = Account::where($field, $login)->with('role')->first();

        if (! $account || ! $mfa->appliesTo($account)) {
            $this->error("No staff account found for '{$login}'.");

            return self::FAILURE;
        }

        if (! $mfa->isEnabled($account)) {
            $this->info('That account has no two-step verification set up. Nothing to reset.');

            return self::SUCCESS;
        }

        $mfa->reset($account);
        $mfa->audit($account, 'Two-step verification reset from the server console.', false);

        $this->info("Two-step verification was reset for {$account->email}. They will set it up again at their next sign-in.");

        return self::SUCCESS;
    }
}
