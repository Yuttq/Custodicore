<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdlDisciplinaryRecord extends Model
{
    protected $table = 'pdl_disciplinary_records';
    protected $primaryKey = 'disciplinary_record_id';
    public $timestamps = false; // table only has created_at, no updated_at

    protected $fillable = [
        'pdl_id',
        'incident_date',
        'description',
        'action_taken',
        'triggers_restriction',
        'recorded_by',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'triggers_restriction' => 'boolean',
    ];

    const CREATED_AT = 'created_at';

    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(Account::class, 'recorded_by');
    }
}
