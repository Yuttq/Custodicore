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
        // $timestamps is off, so Laravel doesn't cast this by itself; without
        // it, created_at is a plain string and ->format() crashes the pages.
        'created_at' => 'datetime',
    ];

    const CREATED_AT = 'created_at';

    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    // Restrictions linked to this incident. "Led to restriction" is shown
    // from these, not from the legacy triggers_restriction column.
    public function restrictions()
    {
        return $this->hasMany(PdlRestriction::class, 'disciplinary_record_id', 'disciplinary_record_id');
    }

    public function ledToRestriction(): bool
    {
        return $this->restrictions->isNotEmpty();
    }

    // Short label for pickers and the restriction list.
    public function shortLabel(): string
    {
        return $this->incident_date->format('M j, Y') . ' — ' . \Illuminate\Support\Str::limit($this->description, 50);
    }

    public function recordedBy()
    {
        return $this->belongsTo(Account::class, 'recorded_by');
    }
}
