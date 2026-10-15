<?php

namespace App\Http\Controllers;

use App\Models\Pdl;
use App\Models\VisitRequest;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            // Total = PDLs currently in custody; released, transferred and
            // deceased PDLs are subtracted from it.
            // enum('active','released','transferred','deceased'), default 'active'.
            'total_pdls' => Pdl::where('custody_status', 'active')->count(),
            'released' => Pdl::where('custody_status', 'released')->count(),
            'transferred' => Pdl::where('custody_status', 'transferred')->count(),
            'no_visit_record' => Pdl::where('custody_status', 'active')
                ->whereDoesntHave('visitRequests')
                ->count(),
            // Visitor-submitted requests (`assigned`) awaiting staff review.
            'pending_visit_requests' => VisitRequest::awaitingReview()->count(),
        ];

        // "Recent Records Activity" — there is no confirmed custody-history
        // or audit-log table yet, so this is a proxy: the PDL profiles most
        // recently touched (by updated_at), not a true per-change history.
        // Once audit_logs' columns are confirmed, this should probably pull
        // from there instead — it'll actually say *what* changed, not just
        // *that* something did.
        $recentActivity = Pdl::orderBy('updated_at', 'desc')->take(5)->get();

        // Now real: PDLs with zero visit_requests at all, most recently
        // admitted first (a reasonable proxy for "most overdue attention").
        $visitationAlerts = Pdl::where('custody_status', 'active')
            ->whereDoesntHave('visitRequests')
            ->orderBy('admission_date', 'desc')
            ->take(5)
            ->get();

        return view('dashboard', compact('stats', 'recentActivity', 'visitationAlerts'));
    }
}
