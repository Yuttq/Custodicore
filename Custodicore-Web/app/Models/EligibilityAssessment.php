<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EligibilityAssessment extends Model
{
    protected $table = 'eligibility_assessments';
    protected $primaryKey = 'assessment_id';
    public $timestamps = false; // has assessed_at instead

    const CREATED_AT = null;

    protected $fillable = [
        'visit_request_id',
        'identity_check_result',
        'relationship_check_result',
        'history_check_result',
        'pdl_restriction_check_result',
        'overall_result',
        'flagged_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id', 'visit_request_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(StaffProfile::class, 'reviewed_by', 'staff_id');
    }

    public function needsReview(): bool
    {
        return $this->overall_result === 'flagged_for_review' && $this->reviewed_at === null;
    }

    /**
     * Run the four checks for a visit request and store the result.
     * Re-running for the same visit_request updates the existing row
     * (visit_request_id is unique) rather than creating a duplicate —
     * useful since e.g. an ID getting verified after the fact should let
     * staff re-run the check rather than leaving a stale assessment.
     *
     * NOTE: this reads the *current* state of IDs/relationships/flags/
     * restrictions at the moment it's run. It's a snapshot, not something
     * that updates itself automatically if those change later — re-run it
     * explicitly (the "Run Eligibility Check" button) if something's
     * changed since the last assessment.
     */
    public static function assessFor(VisitRequest $visitRequest): self
    {
        $visitor = $visitRequest->visitor;
        $pdl = $visitRequest->pdl;
        $relationship = $visitRequest->relationship;

        // 1. Identity check
        $identityResult = 'flagged';
        if ($visitor) {
            $hasRejectedId = $visitor->idDocuments()->where('verification_status', 'rejected')->exists();
            $hasVerifiedId = $visitor->idDocuments()->where('verification_status', 'verified')->exists();
            if ($hasRejectedId) {
                $identityResult = 'rejected';
            } elseif ($hasVerifiedId) {
                $identityResult = 'pass';
            }
        }

        // 2. Relationship check
        $relationshipResult = ($relationship && $relationship->verification_status === 'verified')
            ? 'pass'
            : 'requires_review';

        // 3. History check
        $historyResult = ($visitor && $visitor->activeFlags()->exists())
            ? 'has_prior_violations'
            : 'clean';

        // 4. PDL restriction check
        $restrictionResult = ($pdl && $pdl->activeRestrictions()->exists())
            ? 'restricted'
            : 'no_restriction';

        // Overall — identity rejection and PDL restriction are hard blockers
        // per the spec doc ("If restricted -> visitation cannot proceed").
        // Everything else that needs a human look gets flagged rather than
        // auto-rejected.
        $flaggedReason = null;
        if ($identityResult === 'rejected') {
            $overall = 'rejected';
            $flaggedReason = 'Visitor identity document was rejected.';
        } elseif ($restrictionResult === 'restricted') {
            $overall = 'rejected';
            $flaggedReason = 'PDL has an active restriction in place.';
        } elseif ($identityResult === 'flagged' || $relationshipResult === 'requires_review' || $historyResult === 'has_prior_violations') {
            $overall = 'flagged_for_review';
            $reasons = [];
            if ($identityResult === 'flagged') $reasons[] = 'identity not yet verified';
            if ($relationshipResult === 'requires_review') $reasons[] = 'relationship not yet verified';
            if ($historyResult === 'has_prior_violations') $reasons[] = 'visitor has active flags';
            $flaggedReason = ucfirst(implode('; ', $reasons)) . '.';
        } else {
            $overall = 'eligible';
        }

        return self::updateOrCreate(
            ['visit_request_id' => $visitRequest->visit_request_id],
            [
                'identity_check_result' => $identityResult,
                'relationship_check_result' => $relationshipResult,
                'history_check_result' => $historyResult,
                'pdl_restriction_check_result' => $restrictionResult,
                'overall_result' => $overall,
                'flagged_reason' => $flaggedReason,
                // Re-running clears any previous review — a changed input
                // means the earlier human sign-off no longer applies to
                // the new result.
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]
        );
    }
}