<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdlLegalRecord extends Model
{
    protected $table = 'pdl_legal_records';
    protected $primaryKey = 'legal_record_id';
    public $timestamps = false; // table only has created_at, no updated_at

    protected $fillable = [
        'pdl_id',
        'case_number',
        'offense_code',
        'offense',
        'court',
        'case_status',
        'remarks',
        'recorded_by',
    ];

    const CREATED_AT = 'created_at';

    // $timestamps is off, so Eloquent won't cast created_at by itself.
    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(Account::class, 'recorded_by');
    }

    /** Drug-related case: a listed RA 9165 offense, or older free text that clearly is one. */
    public function isDrugRelated(): bool
    {
        return $this->offense_code
            ? \App\Support\Offenses::isDrug($this->offense_code)
            : \App\Support\Offenses::looksDrugRelated($this->offense);
    }
}
