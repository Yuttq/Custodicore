<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActingStaff;
use App\Models\RelationshipDocument;
use App\Models\VisitorProfile;
use App\Models\WalkInAssessment;
use App\Services\WalkInService;
use App\Support\Sanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Record Officer's walk-in decision for a visitor registered at the
 * Front Desk. The checks (PDL verification, eligibility, authorization) are
 * filled in by the system; Processed By and the time are set here. The
 * decision is made once: Approved books the date and session the Front Desk
 * chose, in the same step (WalkInService::assess()).
 */
class WalkInAssessmentController extends Controller
{
    use ResolvesActingStaff;

    public function update(Request $request, VisitorProfile $visitor, WalkInService $walkIns): RedirectResponse
    {
        abort_unless($visitor->isWalkIn(), 404);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $options = WalkInAssessment::OPTIONS;
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys($options['decision']))],
            'walk_in_reason' => ['required', Rule::in(array_keys($options['walk_in_reason']))],
            'remarks' => [Rule::requiredIf(fn () => $request->input('walk_in_reason') === 'other' || $request->input('decision') === 'declined'), 'nullable', 'string', 'max:1000'],
        ], [
            'remarks.required' => 'Add remarks explaining the reason (required for "Other" or a rejected walk-in).',
            'decision.required' => 'Choose the walk-in decision.',
            'walk_in_reason.required' => 'Choose the reason for the walk-in.',
        ]);
        $data['remarks'] = Sanitizer::paragraph($data['remarks'] ?? null);

        $back = route('visitor.show', $visitor->visitor_id).'#walk-in';
        try {
            [$assessment, $visit] = $walkIns->assess($visitor, $data, $staffId);
        } catch (ValidationException $e) {
            // The chosen session could not be booked: nothing was saved.
            if (isset($e->errors()['booking'])) {
                return redirect()->to($back)->with('error',
                    'The walk-in was not approved, because its visit could not be booked: '.$e->errors()['booking'][0]
                    .' The decision stays Pending. The chosen date and session cannot be changed: reject this walk-in, and the Front Desk records a new arrival for another session.');
            }

            throw $e;
        }

        $message = "Walk-in assessment saved: {$assessment->label('decision')}.";
        if ($visit) {
            $times = $visit->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ');
            $message .= $visit->schedule->schedule_date->toDateString() === app(\App\Services\ScheduleAvailabilityService::class)->now()->toDateString()
                ? " The Front Desk can check the visitor in now ({$times})."
                : " Visit booked for {$visit->schedule->schedule_date->format('D, M j, Y')} ({$times}); the Front Desk checks the visitor in that day.";
        }

        return redirect()->to($back)->with('success', $message);
    }

    /** Verifies one walk-in document; may link the PDL and verify the relationship (WalkInService::autoLink). */
    public function verifyDocument(VisitorProfile $visitor, RelationshipDocument $document, WalkInService $walkIns): RedirectResponse
    {
        abort_unless($visitor->isWalkIn(), 404);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $note = $walkIns->reviewDocument($visitor, $document, true, null, $staffId);

        return redirect()->to(route('visitor.show', $visitor->visitor_id).'#walk-in')
            ->with('success', trim("{$document->label()} verified. ".($note ?? '')));
    }

    public function rejectDocument(Request $request, VisitorProfile $visitor, RelationshipDocument $document, WalkInService $walkIns): RedirectResponse
    {
        abort_unless($visitor->isWalkIn(), 404);

        $reason = Sanitizer::paragraph($request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.required' => 'Say why the document was rejected.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters.',
        ])['rejection_reason']);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $walkIns->reviewDocument($visitor, $document, false, $reason, $staffId);

        return redirect()->to(route('visitor.show', $visitor->visitor_id).'#walk-in')
            ->with('success', "{$document->label()} rejected.");
    }
}
