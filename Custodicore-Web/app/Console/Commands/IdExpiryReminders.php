<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\VisitorId;
use Illuminate\Console\Command;

/**
 * Daily: tells visitors their ID is about to expire (within
 * VisitorId::EXPIRES_SOON_DAYS) and, once it has, that it expired, so they
 * upload a new one in time (an expired ID blocks booking). Each notice goes
 * once per ID. Record Officers see the same IDs on the Visitor Records
 * sidebar badge (NavBadges).
 *
 * Scheduled daily at 08:00 in routes/console.php.
 */
class IdExpiryReminders extends Command
{
    public const TYPE = 'id_expiry';
    public const TITLE_SOON = 'Your ID Is About to Expire';
    public const TITLE_EXPIRED = 'Your ID Has Expired';

    protected $signature = 'ids:expiry-reminders';

    protected $description = 'Notify visitors whose ID is about to expire or has expired';

    public function handle(): int
    {
        $sent = 0;

        foreach (VisitorId::needingRenewal() as $id) {
            $accountId = $id->visitor->account?->account_id;
            if (! $accountId) {
                continue; // a walk-in without an account: the Front Desk tells them in person
            }

            $expired = $id->expiryState() === 'expired';
            $title = $expired ? self::TITLE_EXPIRED : self::TITLE_SOON;

            $alreadySent = Notification::where('account_id', $accountId)
                ->where('related_record_type', 'visitor_ids')
                ->where('related_record_id', $id->visitor_id_doc_id)
                ->where('title', $title)
                ->exists();
            if ($alreadySent) {
                continue;
            }

            $date = $id->expiry_date->format('M j, Y');
            Notification::notify(
                $accountId,
                self::TYPE,
                $title,
                $expired
                    ? "Your {$id->typeLabel()} expired on {$date}. Upload a new valid ID in the app to book visits again."
                    : "Your {$id->typeLabel()} expires on {$date}. Upload a new valid ID in the app before then, so you can keep booking visits.",
                'visitor_ids',
                $id->visitor_id_doc_id
            );
            $sent++;
        }

        $this->info("ID expiry notices sent: {$sent}");

        return self::SUCCESS;
    }
}
