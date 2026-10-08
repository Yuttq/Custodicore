<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One session (morning or afternoon schedule) of a visit. The session is what
 * holds a seat on its visit_schedules row; the visit itself (VisitRequest) is
 * what counts toward visit.max_per_week.
 */
class VisitSession extends Model
{
    protected $table = 'visit_sessions';
    protected $primaryKey = 'visit_session_id';
    public $timestamps = false;

    protected $fillable = [
        'visit_request_id',
        'schedule_id',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id', 'visit_request_id');
    }

    public function schedule()
    {
        return $this->belongsTo(VisitSchedule::class, 'schedule_id', 'schedule_id');
    }
}
