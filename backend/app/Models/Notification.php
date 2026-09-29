<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public static function notify(int $accountId, string $type, string $title, string $message, ?string $relatedType = null, ?int $relatedId = null): self
    {
        return self::create([
            'account_id' => $accountId,
            'notification_type' => $type,
            'title' => $title,
            'message' => $message,
            'related_record_type' => $relatedType,
            'related_record_id' => $relatedId,
            'is_read' => false,
            'sent_at' => now(),
        ]);
    }
}
