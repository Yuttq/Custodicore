<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One visitor's visit to one PDL on one day — the unit that is approved or
 * confirmed, gets one gate QR, and counts once toward visit.max_per_week.
 * A visit covers one or more sessions of that day (visit_sessions); each
 * session holds a seat on its own visit_schedules row and has its own gate
 * record (visit_checkins). schedule_id is the visit's first session and is
 * always listed in visit_sessions too (added on create).
 */
class VisitRequest extends Model
{
    protected $table = 'visit_requests';
    protected $primaryKey = 'visit_request_id';

    protected $fillable = [
        'visitor_id',
        'pdl_id',
        'relationship_id',
        'schedule_id',
        'status',
        'confirmation_deadline',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'confirmation_deadline' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (VisitRequest $visitRequest) {
            VisitSession::firstOrCreate([
                'visit_request_id' => $visitRequest->visit_request_id,
                'schedule_id' => $visitRequest->schedule_id,
            ]);
        });
    }

    public function visitor()
    {
        return $this->belongsTo(VisitorProfile::class, 'visitor_id', 'visitor_id');
    }

    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    public function relationship()
    {
        return $this->belongsTo(VisitorPdlRelationship::class, 'relationship_id', 'relationship_id');
    }

    public function eligibilityAssessment()
    {
        return $this->hasOne(EligibilityAssessment::class, 'visit_request_id', 'visit_request_id');
    }

    public function schedule()
    {
        return $this->belongsTo(VisitSchedule::class, 'schedule_id', 'schedule_id');
    }

    public function sessions()
    {
        return $this->hasMany(VisitSession::class, 'visit_request_id', 'visit_request_id');
    }

    /** The schedules of this visit's sessions, earliest first. */
    public function sessionSchedules()
    {
        return $this->belongsToMany(VisitSchedule::class, 'visit_sessions', 'visit_request_id', 'schedule_id')
            ->orderBy('visit_schedules.time_slot_start');
    }

    public function qrCode()
    {
        return $this->hasOne(QrCode::class, 'visit_request_id', 'visit_request_id');
    }

    /** Every gate record of this visit, one per session entered. */
    public function checkins()
    {
        return $this->hasMany(VisitCheckin::class, 'visit_request_id', 'visit_request_id');
    }

    /** The most recent gate record. */
    public function checkin()
    {
        return $this->hasOne(VisitCheckin::class, 'visit_request_id', 'visit_request_id')->latestOfMany('checkin_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['assigned', 'pending_confirmation']);
    }
}