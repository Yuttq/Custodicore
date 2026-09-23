<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Module 1.4 Visit Scheduling — read-only view of the visitation slot
 * rules. Rules: drug-related PDLs visit Thu/Sat, non-drug-related
 * Fri/Sun; 9-11:30 AM & 1-4:30 PM; max two visits per week per visitor.
 */
class ScheduleController extends Controller
{
    public function index(): View
    {
        $schedules = [
            ['schedule_date' => '2026-09-24 (Thu)', 'time_slot' => '9:00–11:30 AM', 'classification' => 'drug_related', 'capacity' => '28 / 30'],
            ['schedule_date' => '2026-09-24 (Thu)', 'time_slot' => '1:00–4:30 PM', 'classification' => 'drug_related', 'capacity' => '19 / 30'],
            ['schedule_date' => '2026-09-25 (Fri)', 'time_slot' => '9:00–11:30 AM', 'classification' => 'non_drug_related', 'capacity' => '30 / 30'],
            ['schedule_date' => '2026-09-26 (Sat)', 'time_slot' => '1:00–4:30 PM', 'classification' => 'drug_related', 'capacity' => '12 / 30'],
        ];

        $summary = [
            ['label' => 'Upcoming Slots', 'value' => (string) count($schedules), 'icon' => 'calendar', 'accent' => 'info'],
        ];

        return view('admin.schedules.index', compact('schedules', 'summary'));
    }
}
