<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\VisitorProfile;
use Illuminate\Support\Facades\Log;

/**
 * Tells the visitor in the app that staff did not accept one of their files
 * (a government ID or a relationship document), with the officer's reason.
 *
 * Sent once per rejection, after the rejection is saved. A failure is logged
 * and never undoes the rejection. Visitors without an app account (some
 * walk-ins) are skipped. The type `document_rejected` is shown under
 * Updates in the app (notificationFilters.js).
 */
class DocumentRejectionNotice
{
    public const TYPE = 'document_rejected';

    public static function send(VisitorProfile $visitor, string $documentLabel, string $reason, string $recordType, int $recordId): void
    {
        if (! $visitor->account_id) {
            return;
        }

        try {
            Notification::notify(
                $visitor->account_id,
                self::TYPE,
                'Document Not Accepted',
                "Your {$documentLabel} was not accepted. Reason: {$reason} Please upload a new one in the CustodiCore app so staff can review it again.",
                $recordType,
                $recordId,
            );
        } catch (\Throwable $e) {
            // Record type and id only: no personal data in the log.
            Log::warning("Document rejection notification failed ({$recordType} #{$recordId}): " . $e::class);
        }
    }
}
