<?php

namespace App\Services;

use App\Models\EligibilityAssessment;
use App\Models\PdlLinkRequest;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is waiting for a Record Officer decision. One definition shared by the
 * Review Queue pages, their tab counts and the sidebar badge.
 *
 *   Step 1 — accounts: visitor profiles still pending approval.
 *   Step 2 — visit requests:
 *     - every visitor-submitted request awaiting staff review
 *       (status 'assigned'), whatever its eligibility result, and
 *     - any other visit whose eligibility check is flagged and not yet
 *       reviewed (e.g. a staff-assigned visit).
 *   PDL Links — pending PDL relationships of visitors whose account is
 *     already approved (an additional PDL). A pending account's relationship
 *     is approved together with the account in Step 1, so it is not here.
 *     The tab also lists open visitor requests to add another PDL (Phase
 *     5B), which staff match to the actual PDL; its count includes both.
 */
class ReviewQueue
{
    public static function accountsQuery(): Builder
    {
        // Walk-ins are approved at the Front Desk (WalkInService::approveAtDesk).
        return VisitorProfile::where('verification_status', 'pending')->notWalkIn();
    }

    public static function visitsQuery(): Builder
    {
        return VisitRequest::query()->where(function (Builder $q) {
            // Same scope as Visitor Management's "awaiting review" list, so
            // every request shown there is also in Step 2.
            $q->awaitingReview()
                ->orWhereHas('eligibilityAssessment', fn ($a) => $a->where('overall_result', 'flagged_for_review')->whereNull('reviewed_at'));
        });
    }

    public static function pdlLinksQuery(): Builder
    {
        return VisitorPdlRelationship::where('verification_status', 'pending')
            ->whereHas('visitor', fn (Builder $q) => $q->where('verification_status', 'verified')->notWalkIn());
    }

    /** Open visitor requests to add another PDL, waiting to be matched or rejected. */
    public static function pdlRequestsQuery(): Builder
    {
        return PdlLinkRequest::query()->open();
    }

    public static function counts(): array
    {
        return [
            'accounts' => self::accountsQuery()->count(),
            'visits' => self::visitsQuery()->count(),
            // The PDL Links tab: pending links plus open visitor requests.
            'pdl_links' => self::pdlLinksQuery()->count() + self::pdlRequestsQuery()->count(),
        ];
    }

    /**
     * Can the officer approve this visit right now, and if not, why?
     * Returns [bool $canApprove, ?string $reason]. The services re-check
     * everything when the button is pressed.
     */
    public static function approvalState(VisitRequest $visit, ?array $requirements): array
    {
        $assessment = $visit->eligibilityAssessment;

        if (! $assessment) {
            return [false, 'The eligibility check has not run yet.'];
        }
        if ($assessment->overall_result === 'rejected') {
            return [false, 'Blocked by the eligibility check' . ($assessment->flagged_reason ? ": {$assessment->flagged_reason}" : '.') . ' Reject this request.'];
        }
        // Every request needs its relationship requirements complete, whatever
        // the eligibility result (documents can be rejected after a check passed).
        if ($requirements === null) {
            return [false, 'This request has no PDL relationship on record. Reject it.'];
        }
        if (! RelationshipRequirements::allMet($requirements)) {
            return [false, 'Requirements not complete. Waiting on: ' . implode(', ', RelationshipRequirements::unmetLabels($requirements))];
        }
        if ($visit->status === 'assigned' && $visit->schedule && $visit->schedule->schedule_date->lt(now()->startOfDay())) {
            return [false, 'The visit date has passed. Reject this request.'];
        }

        return [true, null];
    }

    public static function isFlaggedForReview(?EligibilityAssessment $assessment): bool
    {
        return $assessment !== null && $assessment->overall_result === 'flagged_for_review' && $assessment->reviewed_at === null;
    }
}
