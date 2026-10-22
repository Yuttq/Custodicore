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
            ->with('success', "Visit assigned ({$sessions}, pending the visitor's confirmation). Request #{$visitRequest->visit_request_id}.");
    }

    /** Why a session can't be assigned, in words for the officer. */
    private const SLOT_REASONS = [
        ScheduleAvailabilityService::REASON_PAST => 'Date has passed',
        ScheduleAvailabilityService::REASON_ENDED => 'Session has ended',
        ScheduleAvailabilityService::REASON_NOT_ELIGIBLE => 'No visits for this PDL on this day',
        ScheduleAvailabilityService::REASON_NOT_IN_EFFECT => 'Outside the visiting period',
        ScheduleAvailabilityService::REASON_PDL_UNAVAILABLE => 'PDL not available for visits',
        ScheduleAvailabilityService::REASON_CLOSED => 'Session closed',
        ScheduleAvailabilityService::REASON_ALREADY_SCHEDULED => 'Already has a visit with this PDL that day',
        ScheduleAvailabilityService::REASON_FULL => 'Full',
        ScheduleAvailabilityService::REASON_WEEKLY_LIMIT => 'Weekly visit limit reached',
    ];

    /**
     * For the assign form: each relationship's visiting days in the next
     * $days days (days the PDL has visiting hours), with every session and
     * whether it can be assigned. Keyed by relationship_id.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function assignableDays(VisitorProfile $visitor, $relationships, int $days = 30): array
    {
        if ($relationships->isEmpty()) {
            return [];
        }

        $availability = app(ScheduleAvailabilityService::class);
        $from = $availability->now()->startOfDay();
        $result = [];

        foreach ($availability->forRelationships($visitor, $relationships, $from, $from->addDays($days)) as $entry) {
            $result[(int) $entry['relationshipId']] = collect($entry['days'])
                // Only the PDL's visiting days: a day where every session is
                // "no visits on this day" is not offered at all.
                ->reject(fn ($day) => collect($day['slots'])->every(fn ($slot) => in_array($slot['reason'], [
                    ScheduleAvailabilityService::REASON_NOT_ELIGIBLE, ScheduleAvailabilityService::REASON_NOT_IN_EFFECT,
                ], true)))
                ->map(fn ($day) => [
                    'date' => $day['date'],
                    'label' => CarbonImmutable::parse($day['date'])->format('D, M j, Y'),
                    'available' => (bool) $day['available'],
                    'sessions' => collect($day['slots'])->map(fn ($slot) => [
                        'start' => $slot['startTime'],
                        'end' => $slot['endTime'],
                        'period' => ucfirst($slot['period']),
                        'available' => (bool) $slot['available'],
                        'seats' => (int) $slot['slotsRemaining'],
                        'reason' => $slot['available'] ? null : (self::SLOT_REASONS[$slot['reason']] ?? 'Not available'),
                    ])->values()->all(),
                    // Both sessions as one visit, offered like the mobile app's
                    // "Whole day" option (only when the day has more than one).
                    'wholeDay' => $day['wholeDay'] ? [
                        'start' => $day['wholeDay']['startTime'],
                        'end' => $day['wholeDay']['endTime'],
                        'startTimes' => $day['wholeDay']['startTimes'],
                        'available' => (bool) $day['wholeDay']['available'],
                        'seats' => (int) collect($day['slots'])->min('slotsRemaining'),
                        'reason' => $day['wholeDay']['available'] ? null
                            : (self::SLOT_REASONS[$day['wholeDay']['reason']] ?? 'Not available'),
                    ] : null,
                ])
                ->values()
                ->all();
        }

        return $result;
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
