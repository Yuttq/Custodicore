<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\PdlLinkRequest;
use App\Models\VisitorPdlRelationship;
use App\Services\PdlLinkRequestService;
use App\Services\PdlRequestNotOpen;
use App\Services\ReviewQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Review Queue → PDL Links.
 *
 *   - Visitor Requests (Phase 5B): open requests from approved visitors to add
 *     another PDL. Staff open one, view its documents, then match it to the
 *     actual PDL (creates, reuses or reopens a pending relationship) or
 *     reject it. Linking never approves the relationship.
 *   - Pending relationship links of visitors whose account is already
 *     approved. A list only — each row opens the visitor page, where the
 *     documents, confirmations and "Approve relationship" work exactly as
 *     before (also for relationships linked from a request).
 */
class PdlLinkReviewController extends Controller
{
    use Concerns\ResolvesActingStaff;

    public function __construct(private PdlLinkRequestService $requests)
    {
    }

    public function index()
    {
        $pdlRequests = ReviewQueue::pdlRequestsQuery()
            ->with('visitor')
            ->orderBy('created_at')
            ->orderBy('request_id')
            ->paginate(20, ['*'], 'requests_page')
            ->withQueryString();

        $links = ReviewQueue::pdlLinksQuery()
            ->with(['visitor', 'pdl'])
            ->orderBy('created_at')
            ->orderBy('relationship_id')
            ->paginate(20)
            ->withQueryString();

        return view('pdl-links.index', compact('links', 'pdlRequests'));
    }

    public function show(PdlLinkRequest $pdlLinkRequest)
    {
        $pdlLinkRequest->load(['visitor.account', 'documents', 'reviewer', 'relationship.pdl']);

        return view('pdl-links.show', ['pdlRequest' => $pdlLinkRequest]);
    }

    public function link(Request $request, PdlLinkRequest $pdlLinkRequest)
    {
        $validated = $request->validate([
            'pdl_id' => ['required', 'integer', Rule::exists('pdl_profiles', 'pdl_id')],
            'relationship_type' => ['required', Rule::in(VisitorPdlRelationship::RELATIONSHIP_TYPES)],
            'has_children_together' => ['nullable', 'boolean', 'required_if:relationship_type,live_in_partner'],
        ], [
            'pdl_id.required' => 'Search for the PDL and pick it from the list.',
            'pdl_id.exists' => 'The selected PDL was not found.',
            'has_children_together.required_if' => 'Confirm whether the visitor and the PDL have children together.',
        ]);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $result = $this->requests->link(
                $pdlLinkRequest,
                (int) $validated['pdl_id'],
                $validated['relationship_type'],
                $request->filled('has_children_together') ? $request->boolean('has_children_together') : null,
                $staffId
            );
        } catch (PdlRequestNotOpen $e) {
            return back()->with('error', self::notOpenMessage($e));
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error("Linking PDL request #{$pdlLinkRequest->request_id} failed (" . $e::class . '): ' . $e->getMessage());

            return back()->withInput()->with('error', 'Could not link this request. Please try again.');
        }

        /** @var VisitorPdlRelationship $relationship */
        $relationship = $result['relationship']->load(['visitor', 'pdl']);
        $pdlName = $relationship->pdl?->full_name ?? 'a PDL';

        // Same notification as staff adding a PDL from the visitor page.
        VisitorController::notifyAdditionalPdl($relationship->visitor, $relationship, 'relationship_linked', 'PDL Added To Your Account',
            "Facility staff matched your request and linked {$pdlName} to your account as your {$relationship->relationshipLabel()}. "
            . 'Staff will now review the documents you sent. You can request visits with this PDL once the relationship is approved.');

        $done = [
            'created' => 'A new pending relationship was created',
            'reused' => 'The existing pending relationship was used',
            'reopened' => 'The rejected relationship was reopened as pending and its rejected documents were removed (approved ones were kept)',
        ][$result['outcome']];

        return redirect()->route('visitor.show', $relationship->visitor_id)
            ->with('success', "{$done}. Review its documents below and approve the relationship when every requirement is met.");
    }

    public function reject(Request $request, PdlLinkRequest $pdlLinkRequest)
    {
        // Shown to the visitor in the app — keep it to what they can act on.
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.required' => 'Tell the visitor why this request was rejected.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
        ]);
        $reason = trim((string) $validated['rejection_reason']);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $rejected = $this->requests->reject($pdlLinkRequest, $reason, $staffId);
        } catch (PdlRequestNotOpen $e) {
            return back()->with('error', self::notOpenMessage($e));
        }

        $visitor = $rejected->visitor;
        if ($visitor?->account_id) {
            try {
                Notification::notify($visitor->account_id, 'pdl_request_rejected', 'PDL Request Not Approved',
                    "Facility staff could not approve your request to add {$rejected->pdl_name}. Reason: {$reason}"
                    . (preg_match('/[.!?]$/', $reason) ? ' ' : '. ') . 'You can send a new request with the correct details.',
                    'pdl_link_requests', $rejected->request_id);
            } catch (\Throwable $e) {
                // The rejection is already saved; a failed notification must not undo it.
                Log::warning("PDL request rejection notification failed for visitor #{$visitor->visitor_id}: " . $e->getMessage());
            }
        }

        return redirect()->route('pdl-links.index')->with('success', 'Request rejected. The visitor was notified.');
    }

    private static function notOpenMessage(PdlRequestNotOpen $e): string
    {
        return match ($e->status) {
            PdlLinkRequest::STATUS_CANCELLED => 'The visitor cancelled this request, so it can no longer be linked or rejected.',
            PdlLinkRequest::STATUS_LINKED => 'This request was already linked to a PDL.',
            PdlLinkRequest::STATUS_REJECTED => 'This request was already rejected.',
            default => 'This request can no longer be changed.',
        };
    }
}
