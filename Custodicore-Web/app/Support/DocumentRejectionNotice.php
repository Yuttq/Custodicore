<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\VisitorProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /** The visitor's own photo (`photo_rejected`): retaken on Verification Documents. */
    public const PHOTO_TYPE = 'photo_rejected';

    /**
     * $replacementRequested: staff ask for a new copy of an item they had
     * approved ("Replacement Requested"), instead of rejecting one under
     * initial review ("Document Not Accepted"). Same notification type and
     * the same "Reason: … Please upload a new one" ending, so the app's
     * Replace Document action works for both.
     */
    public static function send(VisitorProfile $visitor, string $documentLabel, string $reason, string $recordType, int $recordId, bool $replacementRequested = false): void
    {
        $replacementRequested
            ? self::notify($visitor, self::TYPE, 'Replacement Requested',
                "Facility staff need a new copy of your {$documentLabel}. Reason: {$reason} Please upload a new one in the CustodiCore app so staff can review it again.",
                $recordType, $recordId)
            : self::notify($visitor, self::TYPE, 'Document Not Accepted',
                "Your {$documentLabel} was not accepted. Reason: {$reason} Please upload a new one in the CustodiCore app so staff can review it again.",
                $recordType, $recordId);
    }

    public static function sendPhoto(VisitorProfile $visitor, string $reason, bool $replacementRequested = false): void
    {
        $replacementRequested
            ? self::notify($visitor, self::PHOTO_TYPE, 'Replacement Requested',
                "Facility staff need a new photo of you. Reason: {$reason} Please retake it in the CustodiCore app so staff can review it again.",
                'visitor_profiles', $visitor->visitor_id)
            : self::notify($visitor, self::PHOTO_TYPE, 'Photo Not Accepted',
                "Your photo was not accepted. Reason: {$reason} Please retake it in the CustodiCore app so staff can review it again.",
                'visitor_profiles', $visitor->visitor_id);
    }

    private static function notify(VisitorProfile $visitor, string $type, string $title, string $message, string $recordType, int $recordId): void
    {
        if (! $visitor->account_id) {
            return;
        }

        try {
            // notifications.message holds 500 characters; a reason alone may be 500.
            Notification::notify($visitor->account_id, $type, $title, Str::limit($message, 500), $recordType, $recordId);
        } catch (\Throwable $e) {
            // Record type and id only: no personal data in the log.
            Log::warning("Document rejection notification failed ({$recordType} #{$recordId}): " . $e::class);
        }
    }
}
