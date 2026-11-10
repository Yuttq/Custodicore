<?php

namespace App\Http\Controllers;

use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitSchedule;
use App\Services\ScheduleAvailabilityService;
use App\Services\VisitAssignmentService;
use Carbon\CarbonImmutable;
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
        // The form sends a visiting day and one or more of its sessions
        // (morning, afternoon, or both for a whole day). A single existing
        // schedule_id is still accepted.
        if (! $request->filled('schedule_id')) {
            return $this->storeSessions($request, $visitor);
        }

        $validated = $request->validate([
            'pdl_id' => ['required', 'integer', 'exists:pdl_profiles,pdl_id'],
            'relationship_id' => ['required', 'integer', 'exists:visitor_pdl_relationships,relationship_id'],
            'schedule_id' => ['required', 'integer', 'exists:visit_schedules,schedule_id'],
            'confirmation_deadline' => ['nullable', 'date', 'after:now'],
        ]);

        $schedule = VisitSchedule::find($validated['schedule_id']);
        if ($error = $this->deadlineError($validated['confirmation_deadline'] ?? null,
            $schedule->schedule_date->toDateString() . ' ' . substr((string) $schedule->time_slot_start, 0, 5))) {
            return back()->withErrors(['confirmation_deadline' => $error])->withInput();
        }

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
            ->with('success', $this->assignedMessage($visitRequest, 'one session'));
    }

    /**
     * A confirmation deadline the officer typed must fall before the visit
     * starts; a deadline after the visit could never be met. Left blank, the
     * facility's default window applies.
     */
    private function deadlineError(?string $deadline, string $visitStart): ?string
    {
        if (! $deadline) {
            return null;
        }
        $tz = app(ScheduleAvailabilityService::class)->timezone();
        $start = CarbonImmutable::parse($visitStart, $tz);

        return CarbonImmutable::parse($deadline, $tz)->gte($start)
            ? 'The confirmation deadline must be before the visit starts (' . $start->format('M j, Y H:i') . ').'
            : null;
    }

    private function storeSessions(Request $request, VisitorProfile $visitor)
    {
        $validated = $request->validate([
            'relationship_id' => ['required', 'integer', 'exists:visitor_pdl_relationships,relationship_id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_times' => ['required', 'array', 'min:1', 'max:4'],
            'start_times.*' => ['date_format:H:i', 'distinct'],
            'confirmation_deadline' => ['nullable', 'date', 'after:now'],
        ], [
            'date.required' => 'Choose a visiting day.',
            'start_times.required' => 'Choose a session: morning, afternoon or whole day.',
        ]);

        $relationship = VisitorPdlRelationship::where('relationship_id', $validated['relationship_id'])
            ->where('visitor_id', $visitor->visitor_id)
            ->first();
        if (! $relationship) {
            return back()->withErrors(['relationship_id' => 'Choose one of this visitor\'s relationships.'])->withInput();
        }

        $firstStart = collect($validated['start_times'])->sort()->first();
        if ($error = $this->deadlineError($validated['confirmation_deadline'] ?? null, $validated['date'] . ' ' . $firstStart)) {
            return back()->withErrors(['confirmation_deadline' => $error])->withInput();
        }

        $availability = app(ScheduleAvailabilityService::class);
        try {
            $visitRequest = $this->assignments->assignSessions(
                $visitor,
                $relationship,
                CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $availability->timezone())->startOfDay(),
                $validated['start_times'],
                $validated['confirmation_deadline'] ?? null
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        $sessions = count($validated['start_times']) > 1 ? 'whole day' : 'one session';

        return redirect()
            ->route('visitor.show', $visitor->visitor_id)
            ->with('success', $this->assignedMessage($visitRequest, $sessions));
    }

    /** Confirmed at once for a walk-in without the app; otherwise waiting for the visitor. */
    private function assignedMessage(\App\Models\VisitRequest $visitRequest, string $sessions): string
    {
        return $visitRequest->status === 'confirmed'
            ? "Visit assigned and confirmed ({$sessions}): this walk-in has no app to confirm it. Tell them the date and time. Request #{$visitRequest->visit_request_id}."
            : "Visit assigned ({$sessions}, pending the visitor's confirmation). Request #{$visitRequest->visit_request_id}.";
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
