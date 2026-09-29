<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisitSchedule;
use Illuminate\View\View;

/**
 * Module 1.4 Visit Scheduling — read-only view of the generated visitation
 * slots (see facility_visitation_rules / VisitSchedule, seeded from the
 * fixed policy: drug-related PDLs visit Thu/Sat, non-drug-related
 * Fri/Sun; 9-11:30 AM & 1-4:30 PM).
 */
class ScheduleController extends Controller
{
    public function index(): View
    {
        $schedules = VisitSchedule::query()
            ->with('rule')
            ->orderBy('schedule_date')
            ->orderBy('time_slot_start')
            ->get()
            ->map(fn ($s) => [
                'schedule_date' => $s->schedule_date->format('Y-m-d (D)'),
                'time_slot' => substr($s->time_slot_start, 0, 5) . '–' . substr($s->time_slot_end, 0, 5),
                'classification' => $s->rule?->pdl_classification ?? 'non_drug_related',
                'capacity' => $s->capacityLabel(),
            ])
            ->all();

        $summary = [
            ['label' => 'Upcoming Slots', 'value' => (string) count($schedules), 'icon' => 'calendar', 'accent' => 'info'],
        ];

        return view('admin.schedules.index', compact('schedules', 'summary'));
    }
}
