<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One walk-in arrival: the purpose of the visit (from registration) and the
 * Record Officer's walk-in assessment and decision. See WalkInService.
 */
class WalkInAssessment extends Model
{
    protected $primaryKey = 'assessment_id';

    protected $fillable = [
        'visitor_id',
        'visit_purpose',
        'relationship_type',
        'has_children_together',
        'pdl_id',
        'requested_date',
        'requested_start_times',
        'pdl_verification',
        'visitor_eligibility',
        'authorized_visitor',
        'decision',
        'walk_in_reason',
        'remarks',
        'processed_by',
        'processed_at',
        'visit_request_id',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'has_children_together' => 'boolean',
        'requested_date' => 'date',
        'requested_start_times' => 'array',
    ];

    /** Once a decision is Approved or Rejected it is final (WalkInService::assess()). */
    public function isDecided(): bool
    {
        return $this->decision !== 'pending';
    }

    public function hasRequestedVisit(): bool
    {
        return $this->requested_date !== null && ! empty($this->requested_start_times);
    }

    /** "Fri, Oct 16, 2026 · 09:00 (whole day)" — the date and session(s) the Front Desk chose. */
    public function requestedVisitLabel(): ?string
    {
        if (! $this->hasRequestedVisit()) {
            return null;
        }

        $times = collect($this->requested_start_times)->sort()->values();

        return $this->requested_date->format('D, M j, Y').' · '.$times->implode(' + ')
            .($times->count() > 1 ? ' (whole day)' : '');
    }

    /** field => [value => label], in the order the form lists them. */
    public const OPTIONS = [
        'pdl_verification' => ['verified' => 'Verified', 'not_found' => 'Not Found'],
        'visitor_eligibility' => ['verified' => 'Verified', 'for_review' => 'For Review'],
        'authorized_visitor' => ['yes' => 'Yes', 'no' => 'No', 'for_review' => 'For Review'],
        // 'declined' is the stored value of a rejected walk-in.
        'decision' => ['approved' => 'Approved', 'declined' => 'Rejected', 'pending' => 'Pending'],
        'walk_in_reason' => ['no_prior_booking' => 'No prior booking', 'other' => 'Other'],
    ];

    /** Filled in by the system (WalkInService::checks()), not chosen by the officer. */
    public const AUTOMATIC = ['pdl_verification', 'visitor_eligibility', 'authorized_visitor'];

    /** Purpose types on the walk-in form; "Other" lets the officer type one. */
    public const PURPOSES = ['Family visit', 'Conjugal visit', 'Legal consultation', 'Deliver personal items', 'Deliver food', 'Discuss case or release', 'Emergency or family matter'];

    public function visitor()
    {
        return $this->belongsTo(VisitorProfile::class, 'visitor_id', 'visitor_id');
    }

    /** The PDL the Front Desk selected for this arrival. */
    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id', 'visit_request_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(StaffProfile::class, 'processed_by', 'staff_id');
    }

    public function label(string $field): ?string
    {
        return self::OPTIONS[$field][$this->{$field}] ?? null;
    }
}
