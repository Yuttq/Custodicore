<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function qrCode()
    {
        return $this->hasOne(QrCode::class, 'visit_request_id', 'visit_request_id');
    }

    public function checkin()
    {
        return $this->hasOne(VisitCheckin::class, 'visit_request_id', 'visit_request_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['assigned', 'pending_confirmation']);
    }
}