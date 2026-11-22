<?php

namespace App\Console\Commands;

use App\Services\ReturnedAccounts;
use Illuminate\Console\Command;

/**
 * Daily follow-up of accounts returned for correction (ReturnedAccounts):
 * one reminder after 7 days, closed after 45. Safe to run repeatedly: each
 * account is reminded or closed only once per return.
 * Scheduled in routes/console.php.
 */
class ReturnedAccountsFollowUp extends Command
{
    protected $signature = 'visitors:returned-follow-up';

    protected $description = 'Remind visitors whose account was returned for correction, and close ones left uncorrected too long';

    public function handle(ReturnedAccounts $returned): int
    {
        $reminded = $returned->sendReminders();
        $closed = $returned->closeOverdue();

        $this->info("Reminded {$reminded} returned account(s); closed {$closed}.");

        return self::SUCCESS;
    }
}
