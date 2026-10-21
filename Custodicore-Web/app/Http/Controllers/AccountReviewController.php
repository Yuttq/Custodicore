<?php

namespace App\Http\Controllers;

use App\Models\VisitorProfile;
use Illuminate\Http\Request;

/**
 * Record Officer queue of visitor accounts to review (step 1: identity).
 * Approving/rejecting and verifying IDs use the existing VisitorController
 * actions; this page just gathers what needs doing in one place.
 *
 *   pending  — waiting for approval, oldest first
 *   rejected — accounts returned to the visitor for correction (shown as
 *              "Returned"; the stored status stays 'rejected' for the mobile app)
 */
class AccountReviewController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'rejected' ? 'rejected' : 'pending';

        $queries = [
            'pending' => VisitorProfile::where('verification_status', 'pending')->orderBy('created_at'),
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
            ])
            ->paginate(10)
            ->withQueryString();

        return view('account-reviews.index', compact('visitors', 'counts', 'tab'));
    }
}
