<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffProfile extends Model
{
    protected $table = 'staff_profiles';
    protected $primaryKey = 'staff_id';

    protected $fillable = [
        'account_id',
        'employee_number',
        'full_name',
        'position',
        'contact_number',
        'assigned_facility',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
