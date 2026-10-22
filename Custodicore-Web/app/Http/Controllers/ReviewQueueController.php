<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EligibilityAssessment;
use App\Models\Module;
use App\Models\VisitRequest;
use App\Services\RelationshipRequirements;
use App\Services\ReviewQueue;
use App\Services\VisitAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-step decisions in Review Queue → Visit Requests.
 *
 * Approve: if the eligibility check is flagged, record the officer's review
 * as eligible (BJMP requirements must be met), then — for a request the
 * visitor submitted — confirm the visit (VisitAssignmentService). Both happen
 * in one transaction: if confirming fails (date passed, relationship no
 * longer verified, …) the review isn't saved either.
 *
 * Reject: record the review as rejected if it was flagged, and — for a
 * visitor-submitted request — cancel it, release the slot and notify the
 * visitor with the reason.
 *
 * The older two-step routes (eligibility.review + visitor.visit-requests.*)
 * still work unchanged.
 */
class ReviewQueueController extends Controller
{
    public function __construct(private VisitAssignmentService $assignments)
    {
    }

    public function approveVisit(Request $request, VisitRequest $visitRequest)
    {
        $reviewerId = $request->user()?->staffProfile?->staff_id;
        if ($reviewerId === null) {
            return back()->with('error', 'Your account has no staff profile, so this review cannot be recorded. Ask an administrator to complete your staff profile.');
        }

        $visitRequest->load(['eligibilityAssessment', 'relationship', 'schedule', 'visitor', 'pdl']);
        $assessment = $visitRequest->eligibilityAssessment;
        $requirements = $visitRequest->relationship ? RelationshipRequirements::for($visitRequest->relationship) : null;

        [$canApprove, $reason] = ReviewQueue::approvalState($visitRequest, $requirements);
        if (! $canApprove) {
            return back()->with('error', "This visit can't be approved yet. {$reason}");
        }

        try {
            DB::transaction(function () use ($visitRequest, $assessment, $reviewerId) {
                if (ReviewQueue::isFlaggedForReview($assessment)) {
                    $this->recordReview($assessment, 'eligible', $reviewerId);
                }
                if ($visitRequest->status === 'assigned') {
                    $this->assignments->approveVisitorRequest($visitRequest);
                }
            });
        } catch (ValidationException $e) {
            return back()->with('error', "This visit can't be approved: " . collect($e->errors())->flatten()->first());
        }

        $who = ($visitRequest->visitor?->full_name ?? 'the visitor') . ' → ' . ($visitRequest->pdl?->full_name ?? 'PDL');

        return back()->with('success', $visitRequest->status === 'assigned'
            ? "Visit approved for {$who}. The visitor has been notified. It is now listed under Upcoming Visits."
            : "Eligibility approved for {$who}.");
    }

    public function rejectVisit(Request $request, VisitRequest $visitRequest)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255', \Illuminate\Validation\Rule::notIn([VisitAssignmentService::REVIEW_EXPIRED_REASON])],
        ], [
            'reason.required' => 'Enter a reason for rejecting this visit request.',
            'reason.min' => 'Give a reason of at least 5 characters so the visitor understands.',
            'reason.not_in' => 'Enter your own reason for rejecting this visit request.',
        ]);

        $reviewerId = $request->user()?->staffProfile?->staff_id;
        if ($reviewerId === null) {
            return back()->with('error', 'Your account has no staff profile, so this review cannot be recorded. Ask an administrator to complete your staff profile.');
        }

        $visitRequest->load(['eligibilityAssessment', 'visitor', 'pdl']);
        $assessment = $visitRequest->eligibilityAssessment;

        if ($visitRequest->status !== 'assigned' && ! ReviewQueue::isFlaggedForReview($assessment)) {
            return back()->with('error', 'This visit request has already been decided.');
        }

        try {
            DB::transaction(function () use ($visitRequest, $assessment, $reviewerId, $data) {
                if (ReviewQueue::isFlaggedForReview($assessment)) {
                    $this->recordReview($assessment, 'rejected', $reviewerId, $data['reason']);
                }
                if ($visitRequest->status === 'assigned') {
                    $this->assignments->rejectVisitorRequest($visitRequest, trim($data['reason']));
                }
            });
        } catch (ValidationException $e) {
            return back()->with('error', "This visit can't be rejected: " . collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Visit request rejected.' . ($visitRequest->status === 'assigned' ? ' The slot was released and the visitor has been notified.' : ''));
    }

    /** Same guarded write as EligibilityController::review (only if still unreviewed). */
    private function recordReview(EligibilityAssessment $assessment, string $decision, int $reviewerId, ?string $reason = null): void
    {
        $changed = EligibilityAssessment::whereKey($assessment->assessment_id)
            ->where('overall_result', 'flagged_for_review')
            ->whereNull('reviewed_at')
            ->update([
                'overall_result' => $decision,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

        if ($changed === 0) {
            throw ValidationException::withMessages(['eligibility' => 'Another officer has already reviewed this request. Refresh the page.']);
        }

        AuditLog::record('update', 'eligibility_assessments', $assessment->assessment_id,
            ($decision === 'eligible' ? 'Approved' : 'Rejected') . " eligibility for visit request #{$assessment->visit_request_id}"
            . ($reason ? ": {$reason}" : ''),
            Module::CODE_ELIGIBILITY_ASSESSMENT);
    }
}
