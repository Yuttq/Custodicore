<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The modules table (section 3.2) — one row per functional module, used by
 * role_permissions (the access matrix) and by audit_logs.module_id (which
 * module an action happened in). See DatabaseSeeder for the seeded rows;
 * the CODE_* constants below must match seeded module_code values exactly.
 */
class Module extends Model
{
    protected $table = 'modules';
    protected $primaryKey = 'module_id';
    public $timestamps = false;

    protected $fillable = ['module_code', 'module_name'];

    const CODE_USER_MANAGEMENT = 'user_management';
    const CODE_PDL_MANAGEMENT = 'pdl_management';
    const CODE_VISITOR_MANAGEMENT = 'visitor_management';
    const CODE_VISIT_SCHEDULING = 'visit_scheduling';
    const CODE_CHECKIN_CHECKOUT = 'checkin_checkout';
    const CODE_ELIGIBILITY_ASSESSMENT = 'eligibility_assessment';
    const CODE_AUDIT_REPORTING = 'audit_reporting';
    const CODE_CUSTODY_HISTORY = 'custody_history';
    const CODE_VISITATION_TRACKING = 'visitation_tracking';

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class, 'module_id', 'module_id');
    }
}
