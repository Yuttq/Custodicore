<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One court hearing of a PDL's case (the next one, or a past one kept as history). */
class CourtHearing extends Model
{
    protected $table = 'pdl_court_hearings';
    protected $primaryKey = 'hearing_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'legal_record_id',
        'hearing_at',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'hearing_at' => 'datetime',
    ];

    public function legalRecord()
    {
        return $this->belongsTo(PdlLegalRecord::class, 'legal_record_id', 'legal_record_id');
    }
}
