<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The role -> module "Can Access" matrix (section 3.3), shown read-only on the Settings pages. */
class RolePermission extends Model
{
    protected $table = 'role_permissions';
    protected $primaryKey = 'role_permission_id';
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = ['role_id', 'module_id', 'can_view', 'can_create', 'can_edit', 'can_delete'];

    protected $casts = [
        'can_view' => 'boolean',
        'can_create' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function module()
    {
        return $this->belongsTo(Module::class, 'module_id', 'module_id');
    }
}
