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
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function typeLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->flag_type));
    }
}
