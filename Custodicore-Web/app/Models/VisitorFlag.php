<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorFlag extends Model
{
    protected $table = 'visitor_flags';
    protected $primaryKey = 'flag_id';
    public $timestamps = false; // only has created_at

    const TYPES = [
        'denied_visit', 'disciplinary_issue', 'officer_conflict',
        'rule_violation', 'prohibited_item_attempt', 'other',
    ];

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'visitor_id',
        'flag_type',
        'description',
        'related_visit_request_id',
        'flagged_by',
        'status',
        'expires_at',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
        // $timestamps is off, so Eloquent won't cast created_at by itself.
        'created_at' => 'datetime',
    ];

    public function visitor()
    {
        return $this->belongsTo(VisitorProfile::class, 'visitor_id', 'visitor_id');
    }

    public function flaggedBy()
    {
        return $this->belongsTo(StaffProfile::class, 'flagged_by', 'staff_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(StaffProfile::class, 'resolved_by', 'staff_id');
    }

    /** How long a new flag can apply, in days (null = until resolved). */
    public const DURATIONS = [
        '7' => '7 days',
        '30' => '30 days',
        '90' => '90 days',
        '180' => '6 months',
        '365' => '1 year',
        'until_resolved' => 'Until resolved',
    ];

    /** Not resolved, and its time limit (if any) hasn't passed. */
    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasExpired(): bool
    {
        return $this->status === 'active' && $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    /** Active, unexpired flags (query). */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * "Flagged until Nov 20, 2026 · 30 days left", "Flagged until resolved",
     * "Expired Nov 20, 2026" or "Resolved Oct 21, 2026".
     */
    public function durationLabel(): string
    {
        if ($this->status === 'resolved') {
            return 'Resolved ' . ($this->resolved_at?->format('M j, Y') ?? '');
        }
        if ($this->expires_at === null) {
            return 'Flagged until resolved';
        }
        if (! $this->expires_at->isFuture()) {
            return 'Expired ' . $this->expires_at->format('M j, Y');
        }

        $days = (int) ceil(now()->floatDiffInDays($this->expires_at));

        return 'Flagged until ' . $this->expires_at->format('M j, Y') . ' · ' . $days . ' ' . ($days === 1 ? 'day' : 'days') . ' left';
    }

    public function typeLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->flag_type));
    }
}
