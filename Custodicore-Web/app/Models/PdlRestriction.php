<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdlRestriction extends Model
{
    protected $table = 'pdl_restrictions';
    protected $primaryKey = 'restriction_id';

    // CONFIRMED full enum from the actual migration — the phpMyAdmin
    // screenshot had truncated this to just the first 3 values.
    const TYPES = [
        'disciplinary', 'quarantine', 'court_order', 'transfer_pending',
        'legal_prohibition', 'victim_related', 'restraining_order',
    ];

    protected $fillable = [
        'pdl_id',
        'disciplinary_record_id',
        'restriction_type',
        'status',
        'start_date',
        'end_date',
        'reason',
        'imposed_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        // A new or lifted restriction changes the eligibility of this PDL's
        // requests still awaiting review: re-check them.
        static::saved(fn (self $restriction) => EligibilityAssessment::recheckAwaitingReview(pdlId: $restriction->pdl_id));

        // An active restriction cancels the PDL's upcoming visits it covers.
        static::saved(function (self $restriction) {
            if ($restriction->isActive() && $restriction->pdl) {
                app(\App\Services\VisitAssignmentService::class)->cancelVisitsForUnavailablePdl(
                    $restriction->pdl,
                    str_replace('_', ' ', $restriction->restriction_type).' restriction from '
                        .$restriction->start_date?->format('M j, Y')
                        .($restriction->end_date ? ' to '.$restriction->end_date->format('M j, Y') : ' (no end date)')
                );
            }
        });
    }

    /**
     * Active restrictions whose dates cover $date: started on or before it
     * and not ended before it (no end date = open-ended).
     */
    public function scopeInEffectOn($query, \DateTimeInterface|string $date)
    {
        $day = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return $query->where('status', 'active')
            ->whereDate('start_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $day));
    }

    /** Whether this restriction is active and its dates cover $date. */
    public function coversDate(\DateTimeInterface|string $date): bool
    {
        $day = \Illuminate\Support\Carbon::parse($date)->startOfDay();

        return $this->isActive()
            && $this->start_date && $this->start_date->lte($day)
            && (! $this->end_date || $this->end_date->gte($day));
    }

    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    // The disciplinary incident that led to this restriction, if any.
    public function disciplinaryRecord()
    {
        return $this->belongsTo(PdlDisciplinaryRecord::class, 'disciplinary_record_id', 'disciplinary_record_id');
    }

    public function imposedBy()
    {
        // Corrected: imposed_by -> staff_profiles.staff_id
        return $this->belongsTo(StaffProfile::class, 'imposed_by', 'staff_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
