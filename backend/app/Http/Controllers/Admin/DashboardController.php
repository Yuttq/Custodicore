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
                'label' => 'Registered PDLs',
                'value' => (string) Pdl::count(),
                'hint' => Pdl::where('custody_status', 'active')->count() . ' active in custody',
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

        return view('admin.dashboard.index', compact(
            'stats',
            'peakHours',
            'visitsTrend',
            'mostVisitedPdls'
        ));
    }
}
