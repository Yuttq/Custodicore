<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The fixed policy from the approved brief, as data rather than hard-coded
 * logic: drug_related PDLs visit Thu/Sat, non_drug_related visit Fri/Sun,
 * 9-11:30 AM & 1-4:30 PM slots. See DatabaseSeeder for the actual seeded
 * rows.
 */
class FacilityVisitationRule extends Model
{
    protected $table = 'facility_visitation_rules';
    protected $primaryKey = 'rule_id';
    public $timestamps = false;

    protected $fillable = [
        'pdl_classification',
        'day_of_week',
        'time_slot_start',
        'time_slot_end',
        'max_capacity',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function schedules()
    {
        return $this->hasMany(VisitSchedule::class, 'rule_id', 'rule_id');
    }
}
