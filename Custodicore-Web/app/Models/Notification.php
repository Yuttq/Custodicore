<?php

namespace App\Models;

use App\Mail\VisitUpdate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * CustodiCore's own notifications table (section 7.2) — distinct from
 * Laravel's built-in notifications system, which this app does not use
 * (see the doc comment on Account::notifications() / Account for why).
 */
class Notification extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'notification_id';
    public $timestamps = false; // has sent_at instead

    protected $fillable = [
        'account_id',
        'notification_type',
        'title',
        'message',
        'related_record_type',
        'related_record_id',
        'is_read',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Visit notification types that are also emailed (VisitUpdate): every
     * visit event in VisitAssignmentService uses `visits` or `visit_confirmed`,
     * plus the two reminders. Account and document notices are not emailed here.
     */
    public const EMAILED_TYPES = [
        'visits',
        'visit_confirmed',
        'visit_reminder_confirm',
        'visit_reminder_today',
        'id_expiry',
    ];

    public static function notify(int $accountId, string $type, string $title, string $message, ?string $relatedType = null, ?int $relatedId = null): self
    {
        $notification = self::create([
            'account_id' => $accountId,
            'notification_type' => $type,
            'title' => $title,
            'message' => $message,
            'related_record_type' => $relatedType,
            'related_record_id' => $relatedId,
            'is_read' => false,
            'sent_at' => now(),
        ]);

        if (in_array($type, self::EMAILED_TYPES, true)) {
            // After commit: a rolled-back action (whose notification row is
            // rolled back too) never emails, and SMTP never runs while row
            // locks are held. Runs at once when no transaction is open.
            // Synchronous on purpose: Azure runs no queue worker.
            DB::afterCommit(fn () => self::emailCopy($notification));
        }

        return $notification;
    }

    /**
     * One email per notification row, so no duplicates beyond the in-app
     * notice itself. Only to an active account with a verified email
     * (walk-ins registered without an email are skipped). A mail failure is
     * logged and never undoes the action; the in-app notice is the record.
     */
    private static function emailCopy(self $notification): void
    {
        $account = Account::find($notification->account_id);
        if (! $account || $account->status !== 'active' || ! $account->email || ! $account->email_verified_at) {
            return;
        }

        try {
            Mail::to($account->email)->send(new VisitUpdate($account, $notification->title, $notification->message));
        } catch (\Throwable $e) {
            // Ids only: no personal data in the log.
            Log::warning("Visit email for notification #{$notification->notification_id} could not be sent (".$e::class.')');
        }
    }
}
