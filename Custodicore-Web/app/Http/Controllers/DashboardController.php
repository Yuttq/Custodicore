<?php

namespace App\Http\Controllers;

use App\Models\Pdl;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_pdls' => Pdl::count(),
            // CONFIRMED from the actual migration:
            // enum('active','released','transferred','deceased'), default 'active'.
            'active_custody' => Pdl::where('custody_status', 'active')->count(),
            'released_transferred' => Pdl::whereIn('custody_status', ['released', 'transferred'])->count(),
            'no_visit_record' => Pdl::where('custody_status', 'active')
                ->whereDoesntHave('visitRequests')
                ->count(),
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
