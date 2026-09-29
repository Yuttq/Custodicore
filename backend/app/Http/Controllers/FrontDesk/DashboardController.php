<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\VisitCheckin;
use App\Models\VisitRequest;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $expectedToday = VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', today()))
            ->whereDoesntHave('checkin')
            ->count();

        $insideNow = VisitCheckin::where('status', 'checked_in')->count();
        $checkedOutToday = VisitCheckin::whereDate('check_out_time', today())->count();

        $stats = [
            ['label' => 'Expected Today', 'value' => (string) $expectedToday, 'hint' => 'Confirmed, not yet checked in', 'accent' => 'info', 'icon' => 'calendar'],
            ['label' => 'Currently Inside', 'value' => (string) $insideNow, 'hint' => 'Checked in, not yet out', 'accent' => 'success', 'icon' => 'check-circle'],
            ['label' => 'Checked Out Today', 'value' => (string) $checkedOutToday, 'hint' => 'Completed visits today', 'accent' => 'warning', 'icon' => 'qrcode'],
        ];

        $upcomingArrivals = VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', today()))
            ->whereDoesntHave('checkin')
            ->with(['visitor', 'pdl', 'schedule'])
            ->orderBy('confirmed_at')
            ->take(5)
            ->get();

        return view('frontdesk.dashboard', compact('stats', 'upcomingArrivals'));
    }
}
