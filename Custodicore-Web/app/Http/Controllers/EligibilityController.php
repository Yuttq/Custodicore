<?php

namespace App\Http\Controllers;

use App\Models\EligibilityAssessment;
use App\Models\VisitRequest;
use App\Services\RelationshipRequirements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EligibilityController extends Controller
{
    /** Same temporary stand-in as the other controllers — see PdlController::currentStaffId(). */
    private function currentStaffId(): int
    {
        $staff = \App\Models\StaffProfile::first();

        if (!$staff) {
            throw new \RuntimeException('No staff_profiles row exists yet. Create one via Tinker first.');
        }

        return $staff->staff_id;
    }

    // -----------------------------------------------------------------
    // Run (or re-run) the four checks for a visit request
    // -----------------------------------------------------------------
    public function store(VisitRequest $visitRequest)
    {
        try {
            $assessment = EligibilityAssessment::assessFor($visitRequest);
        } catch (\Throwable $e) {
            Log::error('Eligibility assessment failed: ' . $e->getMessage());
            return back()->with('error', 'Could not run the eligibility check. ' . $e->getMessage());
        }

        $label = match ($assessment->overall_result) {
            'eligible' => 'Eligible — all checks passed.',
            'flagged_for_review' => 'Flagged for review — ' . $assessment->flagged_reason,
            'rejected' => 'Rejected — ' . $assessment->flagged_reason,
        };

        return back()->with('success', "Eligibility check complete: {$label}");
    }

    // -----------------------------------------------------------------
    // Review queue — assessments flagged for human review
    // -----------------------------------------------------------------
    public function index()
    {
        $assessments = EligibilityAssessment::where('overall_result', 'flagged_for_review')
            ->whereNull('reviewed_at')
            ->with(['visitRequest.visitor.idDocuments', 'visitRequest.pdl', 'visitRequest.relationship.documents'])
            ->orderBy('assessed_at', 'asc') // oldest first — first flagged, first reviewed
            ->paginate(25);

        // BJMP requirements checklist for each request's relationship.
        $requirements = $assessments->getCollection()->mapWithKeys(fn ($a) => [
            $a->assessment_id => $a->visitRequest?->relationship ? RelationshipRequirements::for($a->visitRequest->relationship) : null,
        ]);

        return view('eligibility.index', compact('assessments', 'requirements'));
    }

    // -----------------------------------------------------------------
    // Staff decision on a flagged assessment
    // -----------------------------------------------------------------
    public function review(Request $request, EligibilityAssessment $assessment)
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:eligible,rejected'],
        ]);

        if (! $assessment->needsReview()) {
            return back()->with('error', 'This assessment has already been reviewed.');
        }

        if ($validated['decision'] === 'eligible') {
            $relationship = $assessment->visitRequest?->relationship;
            if (! $relationship) {
                return back()->with('error', 'This visit request has no PDL relationship, so it cannot be approved.');
            }

            $requirements = RelationshipRequirements::for($relationship);
            if (! RelationshipRequirements::allMet($requirements)) {
                return back()->with('error', 'Cannot approve yet. Still needed: '
                    . implode(', ', RelationshipRequirements::unmetLabels($requirements)) . '.');
            }
        }

        try {
            $assessment->update([
                'overall_result' => $validated['decision'],
                'reviewed_by' => $this->currentStaffId(),
                'reviewed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Eligibility review failed: ' . $e->getMessage());
            return back()->with('error', 'Could not save this review. ' . $e->getMessage());
        }

        return redirect()
            ->route('eligibility.index')
            ->with('success', 'Review saved: marked as ' . $validated['decision'] . '.');
    }
}