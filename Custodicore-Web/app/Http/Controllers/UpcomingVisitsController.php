<?php

namespace App\Http\Controllers;

use App\Models\VisitRequest;
use App\Services\ReviewQueue;
use App\Services\ScheduleAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Record Officer — who is coming: visits from today onward that are
 * confirmed, waiting for the visitor to confirm (staff-assigned) or waiting
 * for review, grouped by visit date. Today's list also keeps the visits the
 * front desk has already finished (completed / no-show).
 */
class UpcomingVisitsController extends Controller
{
    public const RANGES = ['today' => 'Today', 'week' => 'Next 7 days', 'month' => 'Next 30 days'];

    public const STATUSES = [
        'confirmed' => 'Confirmed',
        'pending_confirmation' => 'Waiting for visitor to confirm',
        'assigned' => 'Waiting for review',
    ];

    public function index(Request $request, ScheduleAvailabilityService $availability): View
    {
        $range = array_key_exists((string) $request->query('range'), self::RANGES) ? $request->query('range') : 'week';
        $status = array_key_exists((string) $request->query('status'), self::STATUSES) ? $request->query('status') : '';

        $today = $availability->now()->startOfDay();
        $until = match ($range) {
            'today' => $today,
            'month' => $today->addDays(30),
            default => $today->addDays(7),
        };

        $visits = $this->visitsBetween($today->toDateString(), $until->toDateString())
            ->when($status, fn ($q) => $q->where('visit_requests.status', $status))
            ->with(['visitor', 'pdl', 'relationship', 'schedule', 'sessionSchedules', 'checkins'])
            ->get();

        $byDate = $visits->groupBy(fn (VisitRequest $v) => $v->schedule->schedule_date->toDateString());

        $todayKey = $today->toDateString();
        $stats = [
            'today' => $this->visitsBetween($todayKey, $todayKey)->whereIn('visit_requests.status', ['confirmed', 'completed'])->count(),
            'week' => $this->visitsBetween($todayKey, $today->addDays(7)->toDateString())->where('visit_requests.status', 'confirmed')->count(),
            'awaiting_visitor' => $this->visitsBetween($todayKey, null)->where('visit_requests.status', 'pending_confirmation')->count(),
            'awaiting_review' => ReviewQueue::counts()['visits'],
        ];

        return view('upcoming-visits.index', compact('byDate', 'range', 'status', 'stats', 'todayKey'));
    }

    /** Visits whose date is in [$from, $to] (open-ended when $to is null), soonest first. */
    private function visitsBetween(string $from, ?string $to)
    {
        return VisitRequest::query()
            ->select('visit_requests.*')
            ->join('visit_schedules', 'visit_schedules.schedule_id', '=', 'visit_requests.schedule_id')
            ->whereDate('visit_schedules.schedule_date', '>=', $from)
            ->when($to, fn ($q) => $q->whereDate('visit_schedules.schedule_date', '<=', $to))
            ->where(function ($q) use ($from) {
                $q->whereIn('visit_requests.status', array_keys(self::STATUSES))
                    // Today's finished visits stay on the list.
                    ->orWhere(fn ($q) => $q->whereIn('visit_requests.status', ['completed', 'no_show'])
                        ->whereDate('visit_schedules.schedule_date', $from));
            })
            ->orderBy('visit_schedules.schedule_date')
            ->orderBy('visit_schedules.time_slot_start')
            ->orderBy('visit_requests.visit_request_id');
    }
}
