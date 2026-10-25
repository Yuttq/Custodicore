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

/**
 * The Record Officer's walk-in decision for a visitor registered at the
 * Front Desk. The checks (PDL verification, eligibility, authorization) are
 * filled in by the system; Processed By and the time are set here. An
 * `approved` decision lets the Front Desk book today's visit (WalkInService).
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

        $assessment = $walkIns->assess($visitor, $data, $staffId);

        $message = "Walk-in assessment saved: {$assessment->label('decision')}.";
        if ($assessment->decision === 'approved') {
            // The visitor is at the facility: set up today's visit so the Front Desk only checks them in.
            $visit = $walkIns->autoBook($visitor);
            $message .= $visit
                ? ' The Front Desk can check the visitor in now ('.$visit->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ').').'
                : ' Today\'s visiting hours are over, so the visitor cannot be checked in today.';
        }

        return redirect()->to(route('visitor.show', $visitor->visitor_id).'#walk-in')->with('success', $message);
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
