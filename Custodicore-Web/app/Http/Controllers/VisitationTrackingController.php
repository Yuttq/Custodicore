<?php

namespace App\Http\Controllers;

use App\Models\Pdl;
use App\Models\VisitRequest;

class VisitationTrackingController extends Controller
{
    // -----------------------------------------------------------------
    // LIST — PDLs flagged by how recently (or whether) they've had a
    // confirmed visit.
    // -----------------------------------------------------------------
    public function index()
    {
        $noVisitCutoff = now()->subDays(30);

        // "No visit record" = no visit_requests row at all (ever), joined
        // via a NOT EXISTS-style whereDoesntHave.
        $pdls = Pdl::query()
            ->where('custody_status', 'active') // only currently-detained PDLs are relevant here
            ->withCount('visitRequests')
            ->orderBy('admission_date', 'desc')
            ->paginate(25);

        // Compute a simple flag per row for the view: no visits ever, no
        // recent confirmed visit, or fine. This is intentionally simple —
        // "confirmed" is the one enum value we're fully sure exists on
        // visit_requests.status (assigned/pending_confirmation/confirmed
        // were visible; the enum was truncated past that in phpMyAdmin).
        foreach ($pdls as $pdl) {
            if ($pdl->visit_requests_count === 0) {
                $pdl->visitFlag = 'no_visit';
            } else {
                $hasRecentConfirmed = VisitRequest::where('pdl_id', $pdl->pdl_id)
                    ->where('status', 'confirmed')
                    ->where('confirmed_at', '>=', $noVisitCutoff)
                    ->exists();
                $pdl->visitFlag = $hasRecentConfirmed ? 'recent_visit' : 'long_time_no_visit';
            }
        }

        $noVisitCount = Pdl::where('custody_status', 'active')
            ->whereDoesntHave('visitRequests')
            ->count();

        return view('visitation-tracking', compact('pdls', 'noVisitCount'));
    }

    // -----------------------------------------------------------------
    // SHOW — one PDL's visitor relationships + visit request history
    // -----------------------------------------------------------------
    public function show(Pdl $pdl)
    {
        $relationships = $pdl->visitorRelationships()->with('visitor')->get();

        $visitRequests = VisitRequest::where('pdl_id', $pdl->pdl_id)
            ->with(['visitor', 'relationship', 'eligibilityAssessment'])
            ->orderBy('assigned_at', 'desc')
            ->get();

        return view('pdl.visit-history', compact('pdl', 'relationships', 'visitRequests'));
    }
}