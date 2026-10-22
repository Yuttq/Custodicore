<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\VisitCheckin;
use App\Models\VisitRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** The card lists: slug => label, description and accent of the card. */
    public const LISTS = [
        'expected' => [
            'label' => 'Expected Visitors',
            'hint' => 'All approved visits coming up',
            'description' => 'Every approved visit that is coming up, today or on a later date, until the visitor checks out for the last time. The Status column shows who is still expected and who is inside.',
            'accent' => 'info',
            'icon' => 'calendar',
        ],
        'inside' => [
            'label' => 'Currently Inside',
            'hint' => 'Checked in, not yet out',
            'description' => 'Visitors checked in at the gate who have not checked out yet.',
            'accent' => 'success',
            'icon' => 'check-circle',
        ],
        'checked-out' => [
            'label' => 'Checked Out Today',
            'hint' => 'Completed visits today',
            'description' => 'Visits completed today: the visitor checked out for the last time.',
            'accent' => 'warning',
            'icon' => 'qrcode',
        ],
    ];

    public function index(): View
    {
        $counts = self::counts();

        $stats = collect(self::LISTS)->map(fn ($card, $key) => $card + [
            'value' => (string) $counts[$key],
            'href' => route('frontdesk.schedule', $key),
            'key' => $key,
        ])->values()->all();

        // Includes a whole-day visitor out for the midday break.
        $upcomingArrivals = CheckinCheckoutController::expectedToday()->take(5)->load('sessionSchedules');

        return view('frontdesk.dashboard', compact('stats', 'upcomingArrivals'));
    }

    /** Live card counts, polled by the dashboard so check-ins and check-outs show without a reload. */
    public function stats(): JsonResponse
    {
        return response()->json(self::counts());
    }

    /**
     * Everyone behind one dashboard card, for its tab on the Visit Schedule page.
     * Without a date: live (upcoming approved visits, inside now, checked
     * out today). With a date: that day (booked that day, checked in that
     * day and never checked out, checked out that day).
     */
    public static function visits(string $list, ?Carbon $date = null): Collection
    {
        $with = ['visitor', 'pdl', 'schedule', 'sessionSchedules', 'checkins'];

        return match ($list) {
            'expected' => self::expected($date)->with($with)->get()
                ->sortBy(fn (VisitRequest $visit) => $visit->schedule?->schedule_date?->toDateString().' '.$visit->sessionSchedules->min('time_slot_start'))
                ->values(),
            'inside' => self::inside($date)->with($with)->get()
                ->sortByDesc(fn (VisitRequest $visit) => $visit->checkins->max('check_in_time'))
                ->values(),
            'checked-out' => self::checkedOut($date ?? today())->with($with)->get()
                ->sortByDesc(fn (VisitRequest $visit) => $visit->checkins->max('check_out_time'))
                ->values(),
        };
    }

    /** @return array{expected: int, inside: int, checked-out: int} */
    public static function counts(?Carbon $date = null): array
    {
        return [
            'expected' => self::expected($date)->count(),
            // Live: gate records, so a whole-day visitor inside counts once per entry as before.
            'inside' => $date ? self::inside($date)->count() : VisitCheckin::where('status', 'checked_in')->count(),
            'checked-out' => self::checkedOut($date ?? today())->count(),
        ];
    }

    /**
     * Live: approved visits coming up, on any date from today (one per
     * booking, whole-day visits counted once), until the visit is completed.
     * For a date: every approved visit booked that day, however it went.
     */
    private static function expected(?Carbon $date): Builder
    {
        return $date
            ? VisitRequest::whereIn('status', ['confirmed', 'completed', 'no_show'])
                ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', $date))
            : VisitRequest::where('status', 'confirmed')
                ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', '>=', today()));
    }

    /** Live: inside now. For a date: checked in that day and not checked out. */
    private static function inside(?Carbon $date): Builder
    {
        return VisitRequest::whereHas('checkins', fn ($q) => $q->where('status', 'checked_in')
            ->when($date, fn ($day) => $day->whereDate('check_in_time', $date)));
    }

    /** Visits, not gate records: a midday exit is not a completed visit. */
    private static function checkedOut(Carbon $date): Builder
    {
        return VisitRequest::where('status', 'completed')
            ->whereHas('checkins', fn ($q) => $q->whereDate('check_out_time', $date));
    }
}
