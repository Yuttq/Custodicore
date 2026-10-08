<?php

namespace App\Http\Controllers;

use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Services\VisitAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Record Officer — review visitor-submitted visit requests (`assigned`).
 * Approve: assigned -> confirmed. Reject: assigned -> cancelled.
 * Staff-assigned visits (pending_confirmation) are not reviewed here.
 */
class VisitRequestReviewController extends Controller
{
    public function __construct(
        private VisitAssignmentService $assignments
    ) {
    }

    public function approve(VisitorProfile $visitor, VisitRequest $visitRequest)
    {
        abort_unless((int) $visitRequest->visitor_id === (int) $visitor->visitor_id, 404);

        try {
            $this->assignments->approveVisitorRequest($visitRequest);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Visit request #{$visitRequest->visit_request_id} approved. The visitor has been notified.");
    }

    public function reject(Request $request, VisitorProfile $visitor, VisitRequest $visitRequest)
    {
        abort_unless((int) $visitRequest->visitor_id === (int) $visitor->visitor_id, 404);

        // Shown to the visitor in the app — keep it to what they can act on.
        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:255'],
        ], [
            'cancellation_reason.required' => 'Enter a reason for rejecting this visit request.',
        ]);

        try {
            $this->assignments->rejectVisitorRequest($visitRequest, trim($data['cancellation_reason']));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Visit request #{$visitRequest->visit_request_id} rejected. The slot was released and the visitor has been notified.");
    }
}
