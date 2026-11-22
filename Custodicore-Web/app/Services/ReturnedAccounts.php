<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Notification;
use App\Models\VisitorProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Follow-up of accounts returned for correction (verification_status
 * 'rejected', shown as "Returned"), so they don't wait forever:
 *   - one reminder to the visitor after REMIND_AFTER_DAYS;
 *   - closed after CLOSE_AFTER_DAYS (or earlier by an officer).
 *
 * Closing only moves the account to the Review Queue's Closed tab: the
 * status stays 'rejected' (nothing new for the mobile app) and the visitor
 * can still correct it in the app, which reopens it for review
 * (VisitorProfile::resubmitIfReturned clears these fields). Staff can also
 * reopen it. The return date is verified_at (set by Return).
 *
 * Run daily by `visitors:returned-follow-up` (routes/console.php).
 */
class ReturnedAccounts
{
    public const REMIND_AFTER_DAYS = 7;

    public const CLOSE_AFTER_DAYS = 45;

    /** Returned and still open (not closed). */
    public static function openQuery(): Builder
    {
        return VisitorProfile::where('verification_status', 'rejected')->whereNull('return_closed_at');
    }

    public static function closedQuery(): Builder
    {
        return VisitorProfile::where('verification_status', 'rejected')->whereNotNull('return_closed_at');
    }

    /** Sends the one reminder to accounts returned REMIND_AFTER_DAYS ago or more. Returns how many. */
    public function sendReminders(): int
    {
        $sent = 0;
        $due = self::openQuery()
            ->whereNull('return_reminded_at')
            ->where('verified_at', '<=', now()->subDays(self::REMIND_AFTER_DAYS))
            // Ones about to be closed today get the closing notice instead.
            ->where('verified_at', '>', now()->subDays(self::CLOSE_AFTER_DAYS))
            ->pluck('visitor_id');

        foreach ($due as $visitorId) {
            // Conditional: a second run (or a correction meanwhile) sends nothing.
            $claimed = VisitorProfile::whereKey($visitorId)->where('verification_status', 'rejected')
                ->whereNull('return_closed_at')->whereNull('return_reminded_at')
                ->update(['return_reminded_at' => now()]);
            if (! $claimed) {
                continue;
            }

            $visitor = VisitorProfile::find($visitorId);
            $closeOn = $visitor->verified_at->copy()->addDays(self::CLOSE_AFTER_DAYS)->format('M j, Y');
            Notification::notify($visitor->account_id, 'account_rejected', 'Reminder: Your Account Needs a Correction',
                \Illuminate\Support\Str::limit(
                    'Your account is still waiting for your correction, so you cannot book visits yet.'
                    . ($visitor->rejection_reason ? " What to fix: {$visitor->rejection_reason}" : '')
                    . " Please correct it in the app. If it is not corrected by {$closeOn}, the account will be closed.",
                    500),
                'visitor_profiles', $visitor->visitor_id);
            AuditLog::record('update', 'visitor_profiles', $visitor->visitor_id,
                "Reminded {$visitor->full_name} to correct their returned account", Module::CODE_VISITOR_MANAGEMENT);
            $sent++;
        }

        return $sent;
    }

    /** Closes accounts returned CLOSE_AFTER_DAYS ago or more. Returns how many. */
    public function closeOverdue(): int
    {
        $closed = 0;
        $due = self::openQuery()
            ->where('verified_at', '<=', now()->subDays(self::CLOSE_AFTER_DAYS))
            ->pluck('visitor_id');

        foreach ($due as $visitorId) {
            if ($this->close(VisitorProfile::find($visitorId), null)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Closes one returned account. $staffId null = closed automatically.
     * Returns false when it is no longer an open returned account.
     */
    public function close(VisitorProfile $visitor, ?int $staffId): bool
    {
        $done = VisitorProfile::whereKey($visitor->visitor_id)->where('verification_status', 'rejected')
            ->whereNull('return_closed_at')
            ->update(['return_closed_at' => now(), 'return_closed_by' => $staffId]) > 0;
        if (! $done) {
            return false;
        }

        Notification::notify($visitor->account_id, 'account_rejected', 'Account Closed',
            'Your account was closed because it was not corrected'
            . ($staffId === null ? ' within ' . self::CLOSE_AFTER_DAYS . ' days' : '')
            . '. You can still correct it in the app; it will then be reviewed again.',
            'visitor_profiles', $visitor->visitor_id);
        AuditLog::record('update', 'visitor_profiles', $visitor->visitor_id,
            "Closed returned account of {$visitor->full_name} "
                . ($staffId === null ? '(not corrected within ' . self::CLOSE_AFTER_DAYS . ' days)' : '(closed by staff)'),
            Module::CODE_VISITOR_MANAGEMENT);

        return true;
    }

    /** Staff reopen a closed account: back on the Returned list. The visitor is not notified. */
    public function reopen(VisitorProfile $visitor): bool
    {
        $done = VisitorProfile::whereKey($visitor->visitor_id)->where('verification_status', 'rejected')
            ->whereNotNull('return_closed_at')
            ->update(['return_closed_at' => null, 'return_closed_by' => null]) > 0;
        if ($done) {
            AuditLog::record('update', 'visitor_profiles', $visitor->visitor_id,
                "Reopened the closed returned account of {$visitor->full_name} (back on the Returned list)",
                Module::CODE_VISITOR_MANAGEMENT);
        }

        return $done;
    }
}
