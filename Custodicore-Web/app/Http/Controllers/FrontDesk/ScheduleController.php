<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\VisitSchedule;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function index(): View
    {
        $schedules = VisitSchedule::query()
            ->with('rule')
            ->whereBetween('schedule_date', [today(), today()->addDays(7)])
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

        return view('frontdesk.schedule', compact('schedules'));
    }
}
