<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';
    protected $primaryKey = 'role_id';
    public $timestamps = false; // only has created_at

    const CREATED_AT = 'created_at';

    /**
     * Name shown on screen. The stored value 'System Administrator/Warden' is
     * still what the role: middleware, seeders and the mobile app match on, so
     * only the label changes here.
     */
    public static function label(?string $roleName): string
    {
        return $roleName === 'System Administrator/Warden' ? 'Admin' : (string) $roleName;
    }

    protected $fillable = ['role_name', 'description'];

    public function accounts()
    {
        return $this->hasMany(Account::class, 'role_id');
    }

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class, 'role_id', 'role_id');
    }
}
