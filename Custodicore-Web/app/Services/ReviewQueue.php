<?php

namespace App\Services;

use App\Models\PdlLinkRequest;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is waiting for a Record Officer decision. One definition shared by the
 * Review Queue pages, their tab counts and the sidebar badge.
 *
 *   Accounts — visitor profiles still pending approval.
 *   (Visit requests need no review: a booking that meets every rule is
 *   confirmed at once and any other is refused — VisitAssignmentService.)
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
        // Also approved visitors whose replacements wait for Accept replacements.
        return VisitorProfile::where(fn (Builder $q) => $q
            ->where(fn (Builder $pending) => $pending->where('verification_status', 'pending')->notWalkIn())
            ->orWhere(fn (Builder $approved) => $approved->withReplacementsAwaitingReview()));
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
            // The PDL Links tab: pending links plus open visitor requests.
            'pdl_links' => self::pdlLinksQuery()->count() + self::pdlRequestsQuery()->count(),
        ];
    }
}
