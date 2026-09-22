<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Module 1.7 Audit Trail and Basic Reporting — analytics dashboard.
 * All figures below are placeholders; nothing here reads the database yet.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            ['label' => 'Registered PDLs', 'value' => '128', 'hint' => '112 active in custody', 'accent' => 'info'],
            ['label' => 'Verified Visitors', 'value' => '842', 'hint' => '37 pending verification', 'accent' => 'success'],
            ['label' => "Today's Scheduled Visits", 'value' => '46', 'hint' => '9 awaiting confirmation', 'accent' => 'warning'],
            ['label' => 'Active Restrictions', 'value' => '15', 'hint' => 'across 12 PDLs', 'accent' => 'danger'],
        ];

        $peakHours = [
            ['label' => 'Thu 9:00–11:30 AM', 'value' => 92],
            ['label' => 'Thu 1:00–4:30 PM', 'value' => 78],
            ['label' => 'Sat 9:00–11:30 AM', 'value' => 100],
            ['label' => 'Fri 1:00–4:30 PM', 'value' => 64],
            ['label' => 'Sun 9:00–11:30 AM', 'value' => 71],
        ];

        $mostVisitedPdls = [
            ['pdl_number' => 'PDL-0032', 'full_name' => 'Ramon G. Bautista', 'visits_this_month' => 8],
            ['pdl_number' => 'PDL-0014', 'full_name' => 'Ellen M. Cruz', 'visits_this_month' => 7],
            ['pdl_number' => 'PDL-0091', 'full_name' => 'Vicente A. Torres', 'visits_this_month' => 6],
        ];

        $recentActivity = [
            ['description' => 'Front Desk Officer checked in visitor for VIS-2026-0616-001', 'created_at' => '2026-09-22 09:12'],
            ['description' => 'Record Officer verified visitor ID (Passport) for Maria Santos', 'created_at' => '2026-09-22 08:47'],
            ['description' => 'System flagged visitor for manual review (unclear relationship)', 'created_at' => '2026-09-21 16:03'],
            ['description' => 'Warden lifted quarantine restriction on PDL-0055', 'created_at' => '2026-09-21 14:20'],
        ];

        return view('admin.dashboard.index', compact('stats', 'peakHours', 'mostVisitedPdls', 'recentActivity'));
    }
}
