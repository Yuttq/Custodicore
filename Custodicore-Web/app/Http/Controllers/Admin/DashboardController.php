<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pdl;
use App\Models\VisitCheckin;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Module 1.7 Audit Trail and Basic Reporting — analytics dashboard.
 * Now reads the real tables (same ones the Records Officer module writes
 * to) instead of hardcoded sample numbers.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        // Analytics month for Peak Hours ONLY (the Most / Least Visited lists
        // are not filtered: they cover each PDL's whole stay). Defaults to the PREVIOUS month (a finished month gives complete
        // numbers; the current one is still filling up). ?month=YYYY-MM picks
        // any of the last 12 months, including the current one.
        $thisMonth = now()->startOfMonth();
        $monthOptions = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $thisMonth->copy()->subMonths($i);
            $monthOptions[$m->format('Y-m')] = $m->format('F Y');
        }
        $requested = $request->query('month');
        $defaultMonth = $thisMonth->copy()->subMonth()->format('Y-m');
        $selectedMonth = is_string($requested) && isset($monthOptions[$requested]) ? $requested : $defaultMonth;
        $monthStart = Carbon::createFromFormat('Y-m-d', $selectedMonth . '-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $monthLabel = $monthStart->format('F Y');

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

        // The five busiest visiting slots of the chosen month. Every bar is
        // one slot: its height is the share of that slot's capacity that was
        // booked (slots_taken ÷ max_capacity); the tooltip adds the real counts.
        $peakHours = VisitSchedule::query()
            ->whereIn('status', ['open', 'full'])
            ->whereBetween('schedule_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->where('max_capacity', '>', 0)
            ->orderByRaw('slots_taken / max_capacity desc')
            ->orderByDesc('slots_taken')
            ->orderBy('schedule_date')
            ->orderBy('time_slot_start')
            ->take(5)
            ->get()
            ->map(fn ($s) => [
                // x-axis label, as the chart always had it: "Thu 09:00–11:30".
                'label' => ucfirst($s->schedule_date->format('D')) . ' ' . substr($s->time_slot_start, 0, 5) . '–' . substr($s->time_slot_end, 0, 5),
                'date' => $s->schedule_date->format('D, M j, Y'),
                'day' => $s->schedule_date->format('D, M j'),
                'time' => substr($s->time_slot_start, 0, 5) . '–' . substr($s->time_slot_end, 0, 5),
                'taken' => (int) $s->slots_taken,
                'capacity' => (int) $s->max_capacity,
                'value' => (int) round(($s->slots_taken / $s->max_capacity) * 100),
            ])
            ->all();

        $visitsTrend = ['labels' => [], 'values' => []];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->copy()->subDays($i);
            $visitsTrend['labels'][] = $day->format('M j');
            $visitsTrend['values'][] = VisitCheckin::whereDate('check_in_time', $day->toDateString())->count();
        }

        $mostVisitedPdls = VisitRequest::query()
            ->whereNotNull('assigned_at')
            ->selectRaw('pdl_id, count(*) as visits_this_month')
            ->groupBy('pdl_id')
            ->orderByDesc('visits_this_month')
            ->take(5)
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

        // The other end of the same measure: PDLs in active custody who have
        // NEVER had a visit during their stay, so staff can see who is not
        // receiving visitors. The whole list is returned (up to 200); the card
        // scrolls instead of cutting it off.
        $leastVisitedPdls = Pdl::query()
            ->where('custody_status', 'active')
            ->whereDoesntHave('visitRequests', fn ($q) => $q->whereNotNull('assigned_at'))
            ->withMax('visitRequests as last_visit_at', 'assigned_at')
            ->orderBy('last_visit_at')
            ->orderBy('pdl_number')
            ->take(200)
            ->get()
            ->map(fn ($pdl) => [
                'pdl_number' => $pdl->pdl_number,
                'full_name' => $pdl->full_name,
                'last_visit' => $pdl->last_visit_at ? \Illuminate\Support\Carbon::parse($pdl->last_visit_at)->format('M j, Y') : null,
            ])
            ->all();

        return view('admin.dashboard.index', compact(
            'stats',
            'peakHours',
            'visitsTrend',
            'mostVisitedPdls',
            'leastVisitedPdls',
            'monthOptions',
            'selectedMonth',
            'monthLabel',
            'defaultMonth'
        ));
    }
}
