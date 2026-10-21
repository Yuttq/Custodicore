<?php

namespace App\Http\Controllers;

use App\Models\VisitorProfile;
use App\Models\VisitSchedule;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Record Officer — assign a visit request to a visitor (pending_confirmation).
 */
class VisitAssignmentController extends Controller
{
    public function __construct(
        private VisitAssignmentService $assignments
    ) {
    }

    public function store(Request $request, VisitorProfile $visitor)
    {
        $validated = $request->validate([
            'pdl_id' => ['required', 'integer', 'exists:pdl_profiles,pdl_id'],
            'relationship_id' => ['required', 'integer', 'exists:visitor_pdl_relationships,relationship_id'],
            'schedule_id' => ['required', 'integer', 'exists:visit_schedules,schedule_id'],
            'confirmation_deadline' => ['nullable', 'date', 'after:now'],
        ]);

        try {
            $visitRequest = $this->assignments->assign([
                'visitor_id' => $visitor->visitor_id,
                'pdl_id' => (int) $validated['pdl_id'],
                'relationship_id' => (int) $validated['relationship_id'],
                'schedule_id' => (int) $validated['schedule_id'],
                'confirmation_deadline' => $validated['confirmation_deadline'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('visitor.show', $visitor->visitor_id)
            ->with(
                'success',
                "Visit assigned (pending confirmation). Request #{$visitRequest->visit_request_id}."
            );
    }

    /**
     * Open schedules suitable for a PDL classification (used by the assign form).
     * Sessions that have already ended — including earlier today — are left
     * out, by the same rule assign() enforces (sessionHasEnded).
     *
     * @return \Illuminate\Support\Collection<int, VisitSchedule>
     */
    public static function openSchedulesForClassification(string $classification)
    {
        $availability = app(ScheduleAvailabilityService::class);

        return VisitSchedule::query()
            ->with('rule')
            ->where('status', 'open')
            ->whereDate('schedule_date', '>=', now()->toDateString())
            ->whereHas('rule', function ($q) use ($classification) {
                // $classification is a rule group: a classification or "dorm:{id}".
                $q->forGroup($classification)
                    ->where(function ($q) {
                        $q->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', now()->toDateString());
                    })
                    ->whereDate('effective_from', '<=', now()->toDateString());
            })
            ->orderBy('schedule_date')
            ->orderBy('time_slot_start')
            ->get()
            ->reject(fn (VisitSchedule $s) => $availability->sessionHasEnded($s->schedule_date->toDateString(), $s->time_slot_end))
            ->values();
    }
}
