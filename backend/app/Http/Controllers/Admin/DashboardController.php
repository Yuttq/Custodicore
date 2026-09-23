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
            ['label' => 'Registered PDLs', 'value' => '128', 'hint' => '112 active in custody', 'accent' => 'info', 'icon' => 'identification'],
            ['label' => 'Verified Visitors', 'value' => '842', 'hint' => '37 pending verification', 'accent' => 'success', 'icon' => 'check-circle'],
            ['label' => "Today's Scheduled Visits", 'value' => '46', 'hint' => '9 awaiting confirmation', 'accent' => 'warning', 'icon' => 'calendar'],
        ];

        $peakHours = [
            ['label' => 'Thu 9:00–11:30 AM', 'value' => 92],
            ['label' => 'Thu 1:00–4:30 PM', 'value' => 78],
            ['label' => 'Sat 9:00–11:30 AM', 'value' => 100],
            ['label' => 'Fri 1:00–4:30 PM', 'value' => 64],
            ['label' => 'Sun 9:00–11:30 AM', 'value' => 71],
        ];

        // Sample data for the "Visits This Week" bar chart.
        $visitsTrend = [
            'labels' => ['Sep 16', 'Sep 17', 'Sep 18', 'Sep 19', 'Sep 20', 'Sep 21', 'Sep 22'],
            'values' => [34, 41, 38, 52, 47, 60, 46],
        ];

        $mostVisitedPdls = [
            ['pdl_number' => 'PDL-0032', 'full_name' => 'Ramon G. Bautista', 'visits_this_month' => 8],
            ['pdl_number' => 'PDL-0014', 'full_name' => 'Ellen M. Cruz', 'visits_this_month' => 7],
            ['pdl_number' => 'PDL-0091', 'full_name' => 'Vicente A. Torres', 'visits_this_month' => 6],
        ];

        return view('admin.dashboard.index', compact(
            'stats',
            'peakHours',
            'visitsTrend',
            'mostVisitedPdls'
        ));
    }
}
