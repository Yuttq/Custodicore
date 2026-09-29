<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitSchedule extends Model
{
    protected $table = 'visit_schedules';
    protected $primaryKey = 'schedule_id';
    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'schedule_date',
        'time_slot_start',
        'time_slot_end',
        'max_capacity',
        'slots_taken',
        'status',
    ];

    protected $casts = [
        'schedule_date' => 'date',
    ];

    public function rule()
    {
        return $this->belongsTo(FacilityVisitationRule::class, 'rule_id', 'rule_id');
    }

    public function visitRequests()
    {
        return $this->hasMany(VisitRequest::class, 'schedule_id', 'schedule_id');
    }

    public function hasOpenSlots(): bool
    {
        return $this->status === 'open' && $this->slots_taken < $this->max_capacity;
    }

    public function capacityLabel(): string
    {
        return "{$this->slots_taken} / {$this->max_capacity}";
    }
}
