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
        // Includes a whole-day visitor out for the midday break.
        $expected = CheckinCheckoutController::expectedToday();
        $expectedToday = $expected->count();

        $insideNow = VisitCheckin::where('status', 'checked_in')->count();
        // Visits, not gate records: a midday exit is not a completed visit.
        $checkedOutToday = VisitRequest::where('status', 'completed')
            ->whereHas('checkins', fn ($q) => $q->whereDate('check_out_time', today()))
            ->count();

        $stats = [
            ['label' => 'Expected Today', 'value' => (string) $expectedToday, 'hint' => 'Confirmed, not yet checked in', 'accent' => 'info', 'icon' => 'calendar'],
            ['label' => 'Currently Inside', 'value' => (string) $insideNow, 'hint' => 'Checked in, not yet out', 'accent' => 'success', 'icon' => 'check-circle'],
            ['label' => 'Checked Out Today', 'value' => (string) $checkedOutToday, 'hint' => 'Completed visits today', 'accent' => 'warning', 'icon' => 'qrcode'],
        ];

        $upcomingArrivals = $expected->take(5)->load('sessionSchedules');

        return view('frontdesk.dashboard', compact('stats', 'upcomingArrivals'));
    }
}
