<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pdl;
use App\Models\VisitCheckin;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Illuminate\View\View;

/**
 * Module 1.7 Audit Trail and Basic Reporting — analytics dashboard.
 * Now reads the real tables (same ones the Records Officer module writes
 * to) instead of hardcoded sample numbers.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            [
                // In custody now; released/transferred/deceased are subtracted.
                'label' => 'PDLs in Custody',
                'value' => (string) Pdl::where('custody_status', 'active')->count(),
                'hint' => Pdl::where('custody_status', 'released')->count() . ' released · '
                    . Pdl::where('custody_status', 'transferred')->count() . ' transferred',
                'accent' => 'info', 'icon' => 'identification',
            ],
            [
                'label' => 'Verified Visitors',
                'value' => (string) VisitorProfile::where('verification_status', 'verified')->count(),
                'hint' => VisitorProfile::where('verification_status', 'pending')->count() . ' pending verification',
                'accent' => 'success', 'icon' => 'check-circle',
            ],
            [
                'label' => "Today's Scheduled Visits",
                'value' => (string) VisitRequest::whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', today()))->count(),
                'hint' => VisitRequest::where('status', 'pending_confirmation')
                    ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', today()))
                    ->count() . ' awaiting confirmation',
                'accent' => 'warning', 'icon' => 'calendar',
            ],
        ];

        $peakHours = VisitSchedule::query()
            ->whereIn('status', ['open', 'full'])
            ->orderByDesc('slots_taken')
            ->take(5)
            ->get()
            ->map(fn ($s) => [
                'label' => ucfirst($s->schedule_date->format('D')) . ' ' . substr($s->time_slot_start, 0, 5) . '–' . substr($s->time_slot_end, 0, 5),
                'value' => $s->max_capacity > 0 ? (int) round(($s->slots_taken / $s->max_capacity) * 100) : 0,
            ])
            ->all();

        $visitsTrend = ['labels' => [], 'values' => []];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->copy()->subDays($i);
            $visitsTrend['labels'][] = $day->format('M j');
            $visitsTrend['values'][] = VisitCheckin::whereDate('check_in_time', $day->toDateString())->count();
        }

        $mostVisitedPdls = VisitRequest::query()
            ->whereMonth('assigned_at', now()->month)
            ->whereYear('assigned_at', now()->year)
            ->selectRaw('pdl_id, count(*) as visits_this_month')
            ->groupBy('pdl_id')
            ->orderByDesc('visits_this_month')
            ->take(3)
            ->with('pdl')
            ->get()
            ->filter(fn ($row) => $row->pdl !== null)
            ->map(fn ($row) => [
                'pdl_number' => $row->pdl->pdl_number,
                'full_name' => $row->pdl->full_name,
                'visits_this_month' => $row->visits_this_month,
            ])
            ->values()
            ->all();

        // The other end of the same measure: PDLs in active custody with the
        // FEWEST visits assigned this month (zero counts), so staff can see
        // who is not receiving visitors. Ties go to whoever was visited
        // longest ago (never visited first).
        $leastVisitedPdls = Pdl::query()
            ->where('custody_status', 'active')
            ->withCount(['visitRequests as visits_this_month' => fn ($q) => $q
                ->whereMonth('assigned_at', now()->month)
                ->whereYear('assigned_at', now()->year)])
            ->withMax('visitRequests as last_visit_at', 'assigned_at')
            ->orderBy('visits_this_month')
            ->orderBy('last_visit_at')
            ->orderBy('pdl_number')
            ->take(3)
            ->get()
            ->map(fn ($pdl) => [
                'pdl_number' => $pdl->pdl_number,
                'full_name' => $pdl->full_name,
                'visits_this_month' => (int) $pdl->visits_this_month,
                'last_visit' => $pdl->last_visit_at ? \Illuminate\Support\Carbon::parse($pdl->last_visit_at)->format('M j, Y') : null,
            ])
            ->all();

        return view('admin.dashboard.index', compact(
            'stats',
            'peakHours',
            'visitsTrend',
            'mostVisitedPdls',
            'leastVisitedPdls'
        ));
    }
}
