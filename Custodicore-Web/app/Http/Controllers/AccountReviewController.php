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
        $tab = in_array($request->query('tab'), ['rejected', 'closed'], true) ? $request->query('tab') : 'pending';

        $queries = [
            // Walk-ins are approved at the Front Desk, not here. Approved
            // visitors with replacements to accept are listed too.
            'pending' => \App\Services\ReviewQueue::accountsQuery()
                ->orderBy('created_at'),
            // Returned and still open; closed ones (left uncorrected) have their own tab.
            'rejected' => \App\Services\ReturnedAccounts::openQuery()->orderByDesc('verified_at'),
            'closed' => \App\Services\ReturnedAccounts::closedQuery()->orderByDesc('return_closed_at'),
        ];

        $counts = collect($queries)->map(fn ($q) => (clone $q)->count());

        $visitors = $queries[$tab]
            ->with([
                'account',
                'idDocuments' => fn ($q) => $q->orderByDesc('uploaded_at'),
                'idDocuments.verifiedBy',
                'relationships.pdl',
                'relationships.documents',
                'registrationDocuments',
                'flags',
                'walkInAssessments',
            ])
            ->paginate(10)
            ->withQueryString();

        return view('account-reviews.index', compact('visitors', 'counts', 'tab'));
    }
}
