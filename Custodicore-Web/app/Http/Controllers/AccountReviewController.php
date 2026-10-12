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
 *   recheck  — approved before IDs were required to be verified; an officer
 *              should look at their ID again
 *   rejected — rejected accounts (the visitor may have uploaded a new ID)
 */
class AccountReviewController extends Controller
{
    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['recheck', 'rejected'], true) ? $request->query('tab') : 'pending';

        $noVerifiedId = fn ($q) => $q->whereDoesntHave('idDocuments', fn ($d) => $d->where('verification_status', 'verified'));

        $queries = [
            'pending' => VisitorProfile::where('verification_status', 'pending')->orderBy('created_at'),
            'recheck' => VisitorProfile::where('verification_status', 'verified')->where($noVerifiedId)->orderBy('verified_at'),
            'rejected' => VisitorProfile::where('verification_status', 'rejected')->orderByDesc('verified_at'),
        ];

        $counts = collect($queries)->map(fn ($q) => (clone $q)->count());

        $visitors = $queries[$tab]
            ->with([
                'account',
                'idDocuments' => fn ($q) => $q->orderByDesc('uploaded_at'),
                'idDocuments.verifiedBy',
                'relationships.pdl',
            ])
            ->paginate(15)
            ->withQueryString();

        return view('account-reviews.index', compact('visitors', 'counts', 'tab'));
    }
}
