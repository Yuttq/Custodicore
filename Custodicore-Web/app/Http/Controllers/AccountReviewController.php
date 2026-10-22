<?php

namespace App\Http\Controllers;

use App\Models\VisitorProfile;
use Illuminate\Http\Request;

/**
 * Record Officer queue of visitor accounts to review (step 1: identity).
 * Approving/rejecting and verifying IDs use the existing VisitorController
 * actions; this page just gathers what needs doing in one place.
 *
 *   pending  — waiting for approval: walk-ins waiting at the gate first
 *              (registered at the Front Desk today), then oldest first
 *   rejected — accounts returned to the visitor for correction (shown as
 *              "Returned"; the stored status stays 'rejected' for the mobile app)
 */
class AccountReviewController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'rejected' ? 'rejected' : 'pending';

        $queries = [
            'pending' => VisitorProfile::where('verification_status', 'pending')
                ->orderByRaw("CASE WHEN registered_via = 'walk_in' AND created_at >= ? THEN 0 ELSE 1 END", [today()])
                ->orderBy('created_at'),
            'rejected' => VisitorProfile::where('verification_status', 'rejected')->orderByDesc('verified_at'),
        ];

        $counts = collect($queries)->map(fn ($q) => (clone $q)->count());

        $visitors = $queries[$tab]
            ->with([
                'account',
                'idDocuments' => fn ($q) => $q->orderByDesc('uploaded_at'),
                'idDocuments.verifiedBy',
                'relationships.pdl',
                'registrationDocuments',
                'flags',
                'walkInAssessments',
            ])
            ->paginate(10)
            ->withQueryString();

        // Approved walk-ins still waiting for the walk-in decision; the visitor is at the gate.
        $walkInsAwaitingDecision = app(\App\Services\WalkInService::class)->awaitingDecision();

        return view('account-reviews.index', compact('visitors', 'counts', 'tab', 'walkInsAwaitingDecision'));
    }
}
