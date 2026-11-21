<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\VisitorProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tells an approved visitor that staff accepted the replacements they sent
 * (VisitorController::acceptReplacements). When nothing else needs
 * replacing, booking is open again. Sent after the acceptance is saved; a
 * failure is logged and never undoes it. Visitors without an app account
 * (some walk-ins) are skipped.
 */
class ReplacementAcceptedNotice
{
    public const TYPE = 'replacement_accepted';

    /** @param list<string> $labels what was accepted; $stillNeeded what is still waiting */
    public static function send(VisitorProfile $visitor, array $labels, array $stillNeeded): void
    {
        if (! $visitor->account_id) {
            return;
        }

        $message = 'Facility staff accepted your new ' . implode(', ', $labels) . '. '
            . ($stillNeeded === []
                ? 'You can book visits again.'
                : 'You can book visits again once your ' . implode(', ', $stillNeeded) . ' is accepted too.');

        try {
            Notification::notify($visitor->account_id, self::TYPE, 'Replacement Accepted', Str::limit($message, 500),
                'visitor_profiles', $visitor->visitor_id);
        } catch (\Throwable $e) {
            Log::warning("Replacement accepted notification failed (visitor_profiles #{$visitor->visitor_id}): " . $e::class);
        }
    }
}
