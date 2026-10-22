<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Support\Sanitizer;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Visit Schedule: visitors, one tab per dashboard card (expected, inside, checked
 * out), plus a tab with the visiting sessions of the next 7 days. Live by
 * default; ?date= shows one day instead, past or future.
 */
class ScheduleController extends Controller
{
    public const SESSIONS_TAB = 'sessions';

    private const PER_PAGE = 25;

    public function index(Request $request, string $tab = 'expected'): View
    {
        // No date: the live view. A date: that day, past or future.
        $date = $this->selectedDate((string) $request->query('date', ''));
        // Visitor or PDL, matched within the tab; cleaned and capped.
        $query = mb_substr(Sanitizer::text((string) $request->query('q', '')) ?? '', 0, 100);

        $lists = DashboardController::LISTS;
        if ($date) {
            $day = $date->isToday() ? 'today' : $date->format('M j, Y');
            $lists['expected'] = ['label' => 'Booked Visitors', 'description' => "Every approved visit booked for {$day}: still expected, inside, checked out or a no-show."] + $lists['expected'];
            $lists['inside'] = ['label' => $date->isToday() ? 'Currently Inside' : 'Never Checked Out', 'description' => "Visitors who checked in on {$day} and have no check-out recorded."] + $lists['inside'];
            $lists['checked-out'] = ['label' => 'Checked Out', 'description' => "Visits completed on {$day}: the visitor checked out for the last time."] + $lists['checked-out'];
        }

        $data = [
            'tab' => $tab,
            'date' => $date,
            'lists' => $lists,
            'counts' => DashboardController::counts($date),
            'query' => $query,
        ];

        if ($tab === self::SESSIONS_TAB) {
            $data['schedules'] = $this->paginate(collect($this->upcomingSessions($date ?? today())), $request);
        } else {
            $data['card'] = $lists[$tab];
            $visits = DashboardController::visits($tab, $date);
            if ($query !== '') {
                $needle = mb_strtolower($query);
                $visits = $visits->filter(fn (VisitRequest $visit) => str_contains(mb_strtolower(implode(' ', [
                    $visit->visitor?->full_name, $visit->pdl?->full_name, $visit->pdl?->pdl_number,
                ])), $needle))->values();
            }
            $data['visits'] = $this->paginate($visits, $request);
        }

        return view('frontdesk.schedule', $data);
    }

    private function paginate(Collection $items, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $items->forPage($page, self::PER_PAGE)->values(), $items->count(), self::PER_PAGE, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /** The ?date= day, or null (live view) when missing or malformed. */
    private function selectedDate(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function upcomingSessions(Carbon $from): array
    {
        return VisitSchedule::query()
            ->with('rule')
            ->whereBetween('schedule_date', [$from->copy()->startOfDay(), $from->copy()->addDays(7)->endOfDay()])
            ->orderBy('schedule_date')
            ->orderBy('time_slot_start')
            ->get()
            ->map(fn ($s) => [
                'schedule_date' => $s->schedule_date->format('Y-m-d (D)'),
                'time_slot' => substr($s->time_slot_start, 0, 5) . '–' . substr($s->time_slot_end, 0, 5),
                'classification' => $s->rule?->pdl_classification ?? 'non_drug_related',
                'capacity' => $s->capacityLabel(),
                'is_today' => $s->schedule_date->isToday(),
            ])
            ->all();
    }
}
