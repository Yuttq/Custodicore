<?php

namespace App\Http\Controllers;

use App\Models\Pdl;
use App\Models\VisitorProfile;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorFlag;
use App\Models\VisitRequest;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Mail\VisitorAccountApproved;
use App\Services\RelationshipRequirements;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VisitorController extends Controller
{
    use Concerns\ResolvesActingStaff;

    private function logAudit(string $actionType, string $recordType, int $recordId, string $description): void
    {
        try {
            AuditLog::record($actionType, $recordType, $recordId, $description, \App\Models\Module::CODE_VISITOR_MANAGEMENT);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // LIST + SEARCH
    // -----------------------------------------------------------------
    public function index(Request $request)
    {
        $query = trim((string) $request->input('q', ''));
        $status = trim((string) $request->input('status', ''));
        // From the "Flagged" card: only visitors with a flag still in force.
        $flagged = $request->boolean('flagged');
        $idExpiry = $request->boolean('id_expiry');

        $visitors = VisitorProfile::query()
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($w) use ($query) {
                    $w->where('full_name', 'like', "%{$query}%")
                        ->orWhere(fn ($n) => $n->whereNameMatches($query))
                        ->orWhere('contact_number', 'like', "%{$query}%");
                });
            })
            // Walk-ins are approved at the Front Desk, so "Pending" means app
            // sign-ups waiting for Records; pending walk-ins have their own filter.
            ->when($status === 'pending', fn ($q) => $q->where('verification_status', 'pending')->notWalkIn())
            ->when($status === 'walk_in_pending', fn ($q) => $q->where('verification_status', 'pending')->where('registered_via', 'walk_in'))
            ->when(in_array($status, ['verified', 'rejected'], true), fn ($q) => $q->where('verification_status', $status))
            ->when($flagged, fn ($q) => $q->whereHas('activeFlags'))
            // From the sidebar badge: the ID in use expired or expires soon.
            ->when($idExpiry, fn ($q) => $q->whereIn('visitor_id', VisitorId::needingRenewal()->pluck('visitor_id')))
            ->withCount(['idDocuments', 'activeFlags'])
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();

        $stats = [
            'approved_visitors' => VisitorProfile::where('verification_status', 'verified')->count(),
            // Same as the Review Queue's Accounts step: walk-ins are decided at the Front Desk.
            'pending_verification' => \App\Services\ReviewQueue::accountsQuery()->count(),
            'flagged' => VisitorProfile::whereHas('activeFlags')->count(),
        ];

        return view('visitor.index', compact('visitors', 'query', 'status', 'stats', 'flagged', 'idExpiry'));
    }

    // -----------------------------------------------------------------
    // DETAIL / SHOW — profile + ID docs + relationships + flags + assign
    // -----------------------------------------------------------------
    public function show(VisitorProfile $visitor)
    {
        $visitor->load([
            'account',
            'idDocuments',
            'relationships.pdl',
            'relationships.documents',
            'registrationDocuments',
            'walkInAssessments.processedBy',
            'walkInRegisteredBy',
            'flags' => fn ($q) => $q->orderBy('created_at', 'desc'),
            'visitRequests' => fn ($q) => $q->with(['pdl', 'schedule', 'sessionSchedules'])->orderByDesc('assigned_at')->limit(10),
        ]);

        // PDLs this visitor isn't related to yet, for the "Add Relationship" form.
        $relatablePdls = Pdl::whereNotIn('pdl_id', $visitor->relationships->pluck('pdl_id'))
            ->orderByName()
            ->get(['pdl_id', 'pdl_number', 'full_name', 'first_name', 'middle_name', 'last_name', 'suffix', 'alias', 'custody_status', 'photo_path']);

        $activity = \App\Services\ActivityLog::forVisitor($visitor);

        return view('visitor.show', compact('visitor', 'relatablePdls', 'activity'));
    }

    // -----------------------------------------------------------------
    // ACCOUNT REVIEW — approve or reject the visitor's submitted
    // information and documents (visitor_profiles.verification_status).
    // Separate from email verification, which the visitor does themselves.
    // -----------------------------------------------------------------
    public function approve(Request $request, VisitorProfile $visitor)
    {
        if ($visitor->verification_status === 'verified') {
            // A walk-in approved before one Approve also finished the walk-in: finish it now.
            if ($visitor->isWalkIn() && ($staffId = $this->actingStaffId()) !== null) {
                $walkIn = app(\App\Services\WalkInService::class)->approveInOneStep($visitor, $staffId);
                if ($walkIn['notes'] !== []) {
                    return back()->with('success', trim('This visitor is already approved. ' . implode(' ', $walkIn['notes'])));
                }
            }

            return back()->with('success', 'This visitor is already approved.');
        }

        // The PDL and relationship confirmed on the Account Review card.
        $validated = $request->validate([
            'pdl_id' => ['nullable', 'integer', Rule::exists('pdl_profiles', 'pdl_id')],
            'relationship_type' => ['nullable', Rule::in(VisitorPdlRelationship::RELATIONSHIP_TYPES)],
            'has_children_together' => ['nullable', 'boolean'],
            // Officer confirmations ticked on the card, and the adult accompanying a minor.
            'confirmations' => ['nullable', 'array'],
            'confirmations.*' => ['string', Rule::in(array_keys(\App\Services\RelationshipRequirements::CONFIRMATIONS))],
            'accompanying_adult' => ['nullable', 'string', 'max:150'],
        ] + self::idExpiryRules(required: false), self::idExpiryMessages());

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        // One Approve: pending IDs, the account, the PDL link, its documents
        // and confirmations, and the relationship. Refused as a whole while
        // any requirement is incomplete (see AccountApproval).
        try {
            $result = app(\App\Services\AccountApproval::class)->approve(
                $visitor,
                $staffId,
                isset($validated['pdl_id']) ? (int) $validated['pdl_id'] : null,
                $validated['relationship_type'] ?? null,
                $request->filled('has_children_together') ? $request->boolean('has_children_together') : null,
                array_values($validated['confirmations'] ?? []),
                $validated['accompanying_adult'] ?? null
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->with('error', 'This visitor cannot be approved yet. ' . collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            Log::error('Visitor approval failed: ' . $e->getMessage());
            return back()->with('error', 'Could not approve this visitor. ' . $e->getMessage());
        }
        $approvalNotes = implode(' ', $result['notes']);

        // The ID's expiry, read off the ID on the card (optional).
        if ($request->filled('id_expiry_date') || $request->boolean('id_no_expiry')) {
            [$expiry, $noExpiry] = self::idExpiryValues($validated);
            $approvedId = $visitor->fresh()->currentGovernmentId();
            if ($approvedId && $approvedId->verification_status === 'verified') {
                $approvedId->update(['expiry_date' => $expiry, 'no_expiry' => $noExpiry]);
                $this->logAudit('update', 'visitor_ids', $approvedId->visitor_id_doc_id,
                    "Recorded {$approvedId->typeLabel()} expiry for {$visitor->full_name}: " . $approvedId->fresh()->expiryLabel());
            }
        }

        Notification::notify(
            $visitor->account_id,
            'account_approved',
            'Account Approved',
            'Your account has been approved. Your information and documents have been reviewed and approved by staff. You can now use the visitor services available in the app.',
            'visitor_profiles',
            $visitor->visitor_id
        );

        $visitor->loadMissing('account');
        // A walk-in registered without an email has nowhere to send it.
        if ($visitor->account?->email) {
            try {
                Mail::to($visitor->account->email)->send(new VisitorAccountApproved($visitor->account));
            } catch (\Throwable $e) {
                // The in-app notification above is the authoritative record.
                Log::warning('Approval email could not be sent for visitor #' . $visitor->visitor_id . ' (' . $e::class . ')');
            }
        }

        $this->logAudit('update', 'visitor_profiles', $visitor->visitor_id,
            "Approved visitor account: {$visitor->full_name}");

        if ($visitor->isWalkIn()) {
            // One Approve: also the walk-in's files, PDL link and walk-in decision (books the visit).
            $walkIn = app(\App\Services\WalkInService::class)->approveInOneStep($visitor, $staffId);

            return back()->with('success', trim('Walk-in visitor approved. '.$approvalNotes.' '.implode(' ', $walkIn['notes'])));
        }

        return back()->with('success', trim('Visitor approved. The visitor has been notified. '.$approvalNotes));
    }

    public function reject(Request $request, VisitorProfile $visitor)
    {
        // Approval is final under ordinary review: an approved account is not
        // returned for correction. Revoking access uses flags or deactivation.
        if ($visitor->verification_status === 'verified') {
            return back()->with('error', 'This account is already approved and cannot be returned for correction.');
        }

        // Shown to the visitor in the app — keep it to what they can act on.
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
            // Items the visitor must redo (ReturnForCorrection::items keys). Ticking
            // one rejects it, which is what lets the app accept a new copy.
            'redo' => ['nullable', 'array'],
            'redo.*' => ['string', 'max:30'],
        ], [
            'rejection_reason.required' => 'Tell the visitor what to fix before their account can be approved.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
        ]);
        $reason = trim((string) $data['rejection_reason']);

        if ($visitor->verification_status === 'rejected') {
            return back()->with('error', 'This account has already been returned to the visitor.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $redone = DB::transaction(function () use ($visitor, $staffId, $reason, $data) {
                // Same row lock as the final Approve and the per-item rejects.
                VisitorProfile::whereKey($visitor->visitor_id)->lockForUpdate()->first();

                $redone = \App\Services\ReturnForCorrection::rejectForReturn($visitor, $data['redo'] ?? [], $reason, $staffId);
                $visitor->update([
                    'verification_status' => 'rejected',
                    'verified_by' => $staffId,
                    'verified_at' => now(),
                    'rejection_reason' => $reason,
                ]);
                // A fresh return: its own reminder and closing date (ReturnedAccounts).
                VisitorProfile::whereKey($visitor->visitor_id)
                    ->update(['return_reminded_at' => null, 'return_closed_at' => null, 'return_closed_by' => null]);

                return $redone;
            });
        } catch (\Throwable $e) {
            Log::error('Visitor rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Could not return this account. ' . $e->getMessage());
        }

        $redoText = $redone !== [] ? ' Upload a new ' . implode(', ', $redone) . ' in the app.' : '';

        Notification::notify(
            $visitor->account_id,
            'account_rejected',
            'Account Returned for Correction',
            \Illuminate\Support\Str::limit(
                'Your account was returned so you can correct your information or documents.' . ($reason ? " What to fix: {$reason}" : ' Please contact facility staff for details.') . $redoText,
                500
            ),
            'visitor_profiles',
            $visitor->visitor_id
        );

        $this->logAudit('update', 'visitor_profiles', $visitor->visitor_id,
            "Returned visitor account to {$visitor->full_name} for correction: {$reason}"
                . ($redone !== [] ? ' (to redo: ' . implode(', ', $redone) . ')' : ''));

        return back()->with('success', 'Account returned. The visitor has been notified of what to fix.'
            . ($redone !== [] ? ' They can now upload a new ' . implode(', ', $redone) . '.' : ''));
    }

    /** Closes a returned account the visitor has not corrected (Review Queue → Returned). */
    public function closeReturn(VisitorProfile $visitor)
    {
        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        if (! app(\App\Services\ReturnedAccounts::class)->close($visitor, $staffId)) {
            return back()->with('error', 'Only a returned account that is still open can be closed.');
        }

        return back()->with('success', "{$visitor->full_name}'s returned account was closed. The visitor was notified and can still correct it in the app.");
    }

    /** Puts a closed returned account back on the Returned list. */
    public function reopenReturn(VisitorProfile $visitor)
    {
        if (! app(\App\Services\ReturnedAccounts::class)->reopen($visitor)) {
            return back()->with('error', 'This account is not a closed returned account.');
        }

        return back()->with('success', "{$visitor->full_name}'s account is back on the Returned list.");
    }

    // -----------------------------------------------------------------
    // IDENTITY VERIFICATION — verify or reject an uploaded ID document
    // -----------------------------------------------------------------
    public function verifyId(VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);

        if ($idDocument->verification_status === 'verified') {
            return back()->with('error', 'This ID is already approved.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $idDocument->update([
                'verification_status' => 'verified',
                'verified_by' => $staffId,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('ID verification failed: ' . $e->getMessage());
            return back()->with('error', 'Could not verify this ID. ' . $e->getMessage());
        }

        $this->logAudit('update', 'visitor_ids', $idDocument->visitor_id_doc_id,
            "Verified {$idDocument->typeLabel()} for {$visitor->full_name}");

        // A walk-in's relationship may now meet every requirement.
        $note = app(\App\Services\WalkInService::class)->autoLink($visitor, $staffId);

        return back()->with('success', trim('ID approved. '.($note ?? '')));
    }

    /**
     * Records when the ID expires, as printed on it, or that it has no
     * expiry date (e.g. the PhilSys National ID). An ID that has expired by
     * the visit date blocks booking (VisitAssignmentService).
     */
    public function setIdExpiry(Request $request, VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);

        if (in_array($idDocument->verification_status, ['rejected', VisitorId::STATUS_SUPERSEDED], true)) {
            return back()->with('error', 'This ID is no longer in use, so its expiry is not recorded.');
        }

        $data = $request->validate(self::idExpiryRules(), self::idExpiryMessages());
        [$expiry, $noExpiry] = self::idExpiryValues($data);

        $idDocument->update(['expiry_date' => $expiry, 'no_expiry' => $noExpiry]);

        $this->logAudit('update', 'visitor_ids', $idDocument->visitor_id_doc_id,
            "Recorded {$idDocument->typeLabel()} expiry for {$visitor->full_name}: " . $idDocument->fresh()->expiryLabel());

        return back()->with('success', 'ID expiry saved: ' . $idDocument->fresh()->expiryLabel() . '.');
    }

    /** id_expiry_date (Y-m-d) or id_no_expiry; both optional here, required_without each other when $required. */
    private static function idExpiryRules(bool $required = true): array
    {
        return [
            'id_no_expiry' => ['nullable', 'boolean'],
            'id_expiry_date' => array_merge(
                $required ? ['required_unless:id_no_expiry,1'] : ['nullable'],
                ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01']
            ),
        ];
    }

    private static function idExpiryMessages(): array
    {
        return [
            'id_expiry_date.required_unless' => 'Enter the expiry date printed on the ID, or tick "No expiry date".',
            'id_expiry_date.date_format' => 'Enter the expiry date printed on the ID as a date.',
            'id_expiry_date.after' => 'Enter the expiry date printed on the ID.',
            'id_expiry_date.before' => 'Enter the expiry date printed on the ID.',
        ];
    }

    /** @return array{0: ?string, 1: bool} [expiry_date, no_expiry] */
    private static function idExpiryValues(array $data): array
    {
        $noExpiry = (bool) ($data['id_no_expiry'] ?? false);

        return [$noExpiry ? null : ($data['id_expiry_date'] ?? null), $noExpiry];
    }

    /** The visitor's own photo (from the mobile app), for staff. */
    public function photo(VisitorProfile $visitor)
    {
        abort_unless($visitor->photo_path && Storage::disk('local')->exists($visitor->photo_path), 404);

        return Storage::disk('local')->response($visitor->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** The uploaded ID picture, for staff to check against the visitor. */
    public function idFile(VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);
        $disk = $idDocument->fileDisk();
        abort_unless($disk !== null, 404);

        return Storage::disk($disk)->response($idDocument->file_path);
    }

    /**
     * Reject one government ID with a reason shown to the visitor. On an ID
     * waiting for review it is an ordinary rejection; on an approved ID it
     * asks the visitor for a new one ("Request replacement"). Only this ID
     * changes; an approved visitor stays approved but cannot book new visits
     * until a new ID is accepted (VisitorProfile::bookingBlockers).
     */
    public function rejectId(Request $request, VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);

        // Shown to the visitor in the app — keep it to what they can act on.
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.required' => 'Tell the visitor why this ID was rejected.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
        ]);
        $reason = trim((string) $data['rejection_reason']);

        if ($idDocument->verification_status === 'rejected') {
            return back()->with('error', 'This ID document is already rejected.');
        }
        if ($idDocument->isSuperseded()) {
            return back()->with('error', 'This ID was set aside as a duplicate.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $wasApproved = $idDocument->verification_status === 'verified';

        // A replacement is requested only for the current (newest) ID; older
        // approved IDs are kept unchanged as history.
        if ($wasApproved && $visitor->currentGovernmentId()?->visitor_id_doc_id !== $idDocument->visitor_id_doc_id) {
            return back()->with('error', 'A newer ID is on file. Request a replacement of the current ID instead; older IDs are kept as history.');
        }

        try {
            // Only the status the officer saw: a second click (or officer) cannot reject it twice.
            $rejected = VisitorId::whereKey($idDocument->visitor_id_doc_id)
                ->where('verification_status', $idDocument->verification_status)
                ->update([
                    'verification_status' => 'rejected',
                    'verified_by' => $staffId,
                    'verified_at' => now(),
                    'rejection_reason' => $reason,
                ]);
        } catch (\Throwable $e) {
            Log::error('ID rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Could not reject this ID. ' . $e->getMessage());
        }
        if ($rejected === 0) {
            return back()->with('error', 'This ID changed while you were reviewing it. Check it again.');
        }

        $this->logAudit('update', 'visitor_ids', $idDocument->visitor_id_doc_id, $wasApproved
            ? "Requested a replacement of the approved {$idDocument->typeLabel()} for {$visitor->full_name}: {$reason}"
            : "Rejected {$idDocument->typeLabel()} for {$visitor->full_name}: {$reason}");

        \App\Support\DocumentRejectionNotice::send($visitor, $idDocument->typeLabel(), $reason,
            'visitor_ids', $idDocument->visitor_id_doc_id, replacementRequested: $wasApproved);
        // The current ID changed: refresh waiting visit requests' identity checks.
        \App\Models\EligibilityAssessment::recheckAwaitingReview(visitorId: $visitor->visitor_id);

        return back()->with('success', $wasApproved
            ? 'Replacement requested. The visitor has been notified. Their other documents are unchanged.'
            : 'ID document rejected.');
    }

    /**
     * Set aside a duplicate government ID waiting for review (two or more
     * IDs pending block the final Approve). The ID is kept with its file and
     * history as `superseded`; the visitor is not notified (they still have
     * another ID under review). The reason is a staff note.
     */
    public function setAsideId(Request $request, VisitorProfile $visitor, VisitorId $idDocument)
    {
        abort_unless($idDocument->visitor_id === $visitor->visitor_id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Say why this ID is set aside (for example: duplicate upload).',
            'reason.min' => 'Give a reason of at least 5 characters.',
        ]);
        $reason = trim((string) $data['reason']);

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $error = DB::transaction(function () use ($visitor, $idDocument, $staffId, $reason) {
            // Same row lock as the final Approve, so the two never cross.
            VisitorProfile::whereKey($visitor->visitor_id)->lockForUpdate()->first();

            if ($visitor->pendingIdCount() < 2) {
                return 'Only a duplicate can be set aside: this visitor has no other ID waiting for review.';
            }
            $updated = VisitorId::whereKey($idDocument->visitor_id_doc_id)
                ->where('verification_status', 'pending')
                ->update([
                    'verification_status' => VisitorId::STATUS_SUPERSEDED,
                    'verified_by' => $staffId,
                    'verified_at' => now(),
                    'rejection_reason' => $reason,
                ]);

            return $updated === 0 ? 'Only an ID waiting for review can be set aside.' : null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        $this->logAudit('update', 'visitor_ids', $idDocument->visitor_id_doc_id,
            "Set aside duplicate {$idDocument->typeLabel()} of {$visitor->full_name}: {$reason}");

        return back()->with('success', 'Duplicate ID set aside. It is kept in the records and was not approved.');
    }

    /**
     * Reject the visitor's own photo with a reason shown to the visitor. On a
     * photo waiting for review it is an ordinary rejection; on an approved
     * photo it asks for a new one ("Request replacement"). Neither changes
     * the account, IDs or documents. A walk-in's photo is retaken at the
     * Front Desk.
     */
    public function rejectPhoto(Request $request, VisitorProfile $visitor)
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.required' => 'Tell the visitor why the photo was rejected.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
        ]);
        $reason = trim((string) $data['rejection_reason']);

        if ($visitor->isWalkIn()) {
            return back()->with('error', 'A walk-in\'s photo is taken at the Front Desk. Ask the Front Desk to retake it.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $seenPath = $visitor->photo_path;
        $wasApproved = $visitor->photo_status === VisitorProfile::PHOTO_VERIFIED;

        try {
            // Rechecked under the same row lock the final Approve takes.
            $error = DB::transaction(function () use ($visitor, $seenPath, $staffId, $reason) {
                $current = VisitorProfile::whereKey($visitor->visitor_id)->lockForUpdate()
                    ->first(['visitor_id', 'photo_path', 'photo_status']);

                if (! $current?->photo_path) {
                    return 'This visitor has no photo yet.';
                }
                if ($current->photo_status === VisitorProfile::PHOTO_REJECTED) {
                    return 'This photo is already rejected.';
                }
                if ($current->photo_path !== $seenPath) {
                    return 'The visitor sent a new photo while you were reviewing. Check it again.';
                }

                VisitorProfile::whereKey($visitor->visitor_id)->update([
                    'photo_status' => VisitorProfile::PHOTO_REJECTED,
                    'photo_rejection_reason' => $reason,
                    'photo_reviewed_by' => $staffId,
                    'photo_reviewed_at' => now(),
                ]);

                return null;
            });
        } catch (\Throwable $e) {
            Log::error('Photo rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Could not reject this photo. ' . $e->getMessage());
        }

        if ($error !== null) {
            return back()->with('error', $error);
        }

        $this->logAudit('update', 'visitor_profiles', $visitor->visitor_id, $wasApproved
            ? "Requested a new photo from {$visitor->full_name}: {$reason}"
            : "Rejected photo for {$visitor->full_name}: {$reason}");

        \App\Support\DocumentRejectionNotice::sendPhoto($visitor, $reason, replacementRequested: $wasApproved);

        return back()->with('success', 'Photo rejected. The visitor has been notified to retake it. Their ID and documents are unchanged.');
    }

    /**
     * Approved visitor: accept the replacements they sent after staff
     * rejected an item or asked for a new copy (VisitorProfile::itemReviewState
     * awaitingReview). Only those replacements, and only while still waiting:
     * anything the officer rejected meanwhile is left alone. Booking opens
     * again once nothing else needs replacing.
     */
    public function acceptReplacements(VisitorProfile $visitor)
    {
        if (! $visitor->isVerified()) {
            return back()->with('error', 'This account is not approved yet. Its items are approved together with the account (Approve).');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        $result = DB::transaction(function () use ($visitor, $staffId) {
            $locked = VisitorProfile::whereKey($visitor->visitor_id)->lockForUpdate()->first();
            $state = $locked->itemReviewState();

            if ($state['awaitingReview'] === []) {
                return ['error' => 'There are no replacements waiting for review.'];
            }
            if (in_array('Visitor Photo', $state['awaitingReview'], true)) {
                $locked->verifyPendingPhoto($staffId, "Accepted the new photo of {$locked->full_name}");
            }

            if (in_array('Government ID', $state['awaitingReview'], true)) {
                // Two or more new IDs: accept the newest, set the older ones aside.
                app(\App\Services\AccountApproval::class)->setAsideOlderPendingIds($locked, $staffId);
                foreach ($locked->idDocuments()->where('verification_status', 'pending')->get() as $id) {
                    $updated = VisitorId::whereKey($id->visitor_id_doc_id)->where('verification_status', 'pending')
                        ->update(['verification_status' => 'verified', 'verified_by' => $staffId, 'verified_at' => now(), 'rejection_reason' => null]);
                    if ($updated) {
                        AuditLog::record('update', 'visitor_ids', $id->visitor_id_doc_id,
                            "Accepted the new {$id->typeLabel()} of {$locked->full_name}", \App\Models\Module::CODE_VISITOR_MANAGEMENT);
                    }
                }
            }

            foreach (\App\Models\RelationshipDocument::whereIn('document_id', $state['awaitingDocumentIds'])->get() as $document) {
                $updated = \App\Models\RelationshipDocument::whereKey($document->document_id)->where('status', 'pending')
                    ->update(['status' => 'verified', 'rejection_reason' => null, 'reviewed_by' => $staffId, 'reviewed_at' => now()]);
                if ($updated) {
                    AuditLog::record('update', 'relationship_documents', $document->document_id,
                        "Accepted the new {$document->label()} of {$locked->full_name}", \App\Models\Module::CODE_VISITOR_MANAGEMENT);
                }
            }

            return ['accepted' => $state['awaitingReview'], 'stillNeeded' => $locked->itemReviewState()['needsReplacement']];
        });

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        // The current ID may have changed: refresh waiting visit requests' checks.
        \App\Models\EligibilityAssessment::recheckAwaitingReview(visitorId: $visitor->visitor_id);

        \App\Support\ReplacementAcceptedNotice::send($visitor, $result['accepted'], $result['stillNeeded']);

        return back()->with('success', 'Replacements accepted: ' . implode(', ', $result['accepted']) . '.'
            . ($result['stillNeeded'] === [] ? ' The visitor can book visits again.' : ' Still waiting for: ' . implode(', ', $result['stillNeeded']) . '.'));
    }

    // -----------------------------------------------------------------
    // RELATIONSHIP VERIFICATION — verify or reject a visitor↔PDL relationship
    // -----------------------------------------------------------------
    // Search box on the "Add PDL Relationship" form: PDLs this visitor isn't
    // related to yet, matched by name, alias, PDL number or ID.
    // include_related=1 (Phase 5B request matching) also returns PDLs the
    // visitor already has a relationship with, each with that relationship's
    // status, so a pending one can be reused or a rejected one reopened.
    public function searchRelatablePdls(Request $request, VisitorProfile $visitor)
    {
        $q = \App\Support\Sanitizer::text((string) $request->query('q', '')) ?? '';
        if (mb_strlen($q) < 1) {
            return response()->json(['results' => []]);
        }

        $includeRelated = $request->boolean('include_related');
        $related = $visitor->relationships()->pluck('verification_status', 'pdl_id');

        $like = \App\Support\Sanitizer::like($q);
        $pdls = Pdl::query()
            ->when(! $includeRelated, fn ($w) => $w->whereNotIn('pdl_id', $related->keys()))
            ->where(function ($w) use ($q, $like) {
                $w->where('full_name', 'like', $like)
                    ->orWhere(fn ($n) => $n->whereNameMatches($q))
                    ->orWhere('alias', 'like', $like)
                    ->orWhere('pdl_number', 'like', $like);
                if (ctype_digit($q)) {
                    $w->orWhere('pdl_id', (int) $q);
                }
            })
            // Exact number/ID matches first, then PDLs in custody, then by name.
            ->orderByRaw('CASE WHEN pdl_number = ? OR pdl_id = ? THEN 0 ELSE 1 END', [$q, ctype_digit($q) ? (int) $q : 0])
            ->orderByRaw("CASE WHEN custody_status = 'active' THEN 0 ELSE 1 END")
            ->orderByName()
            ->limit(8)
            ->get(['pdl_id', 'pdl_number', 'full_name', 'first_name', 'middle_name', 'last_name', 'suffix', 'alias', 'custody_status', 'photo_path']);

        return response()->json([
            'results' => $pdls->map(fn (Pdl $p) => [
                'id' => $p->pdl_id,
                'number' => $p->pdl_number,
                'name' => $p->listName(),
                'alias' => $p->alias,
                'status' => $p->custody_status,
                'photo' => $p->photoUrl(),
                'initials' => $p->initials(),
            ] + ($includeRelated ? ['relationshipStatus' => $related[$p->pdl_id] ?? null] : []))->values(),
        ]);
    }

    // Staff register the relationship as pending; verifying it is a separate
    // step (verifyRelationship below).
    public function storeRelationship(Request $request, VisitorProfile $visitor)
    {
        $validated = $request->validate([
            'pdl_id' => [
                'required', 'integer',
                Rule::exists('pdl_profiles', 'pdl_id'),
                Rule::unique('visitor_pdl_relationships', 'pdl_id')->where('visitor_id', $visitor->visitor_id),
            ],
            'relationship_type' => ['required', Rule::in(VisitorPdlRelationship::RELATIONSHIP_TYPES)],
            'has_children_together' => ['nullable', 'boolean'],
        ], [
            'pdl_id.unique' => 'This visitor already has a relationship with the selected PDL.',
        ]);

        // BJMP priority: spouse, parents and legal guardian.
        $validated['priority_tier'] = VisitorPdlRelationship::priorityTierFor($validated['relationship_type']);
        $validated['has_children_together'] = $validated['relationship_type'] === 'live_in_partner' && $request->filled('has_children_together')
            ? $request->boolean('has_children_together')
            : null;

        try {
            $relationship = VisitorPdlRelationship::create([
                ...$validated,
                'visitor_id' => $visitor->visitor_id,
                'verification_status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Unique (visitor_id, pdl_id) — a concurrent request got there first.
            return back()->withInput()->withErrors([
                'pdl_id' => 'This visitor already has a relationship with the selected PDL.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Relationship creation failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Could not add this relationship. ' . $e->getMessage());
        }

        $this->logAudit('create', 'visitor_pdl_relationships', $relationship->relationship_id,
            "Added relationship: {$visitor->full_name} as {$relationship->relationshipLabel()} to PDL #{$relationship->pdl_id} (pending verification)");

        $pdlName = $relationship->pdl?->full_name ?? 'a PDL';
        $this->notifyAdditionalPdl($visitor, $relationship, 'relationship_linked', 'PDL Added To Your Account',
            "Facility staff linked {$pdlName} to your account as your {$relationship->relationshipLabel()}. "
            . 'Upload the required documents under Verification Documents. Staff will review them before you can request visits with this PDL.');

        return redirect()->route('visitor.show', $visitor->visitor_id)
            ->with('success', 'Relationship added. It is pending verification.');
    }

    public function verifyRelationship(Request $request, VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);

        if ($relationship->verification_status === 'verified') {
            return back()->with('error', 'This relationship is already approved.');
        }
        // A rejected relationship is reopened (as pending) only by linking the
        // visitor's new Add Another PDL request; it is never approved directly.
        if ($relationship->verification_status === 'rejected') {
            return back()->with('error', 'This relationship was rejected and cannot be approved directly. The visitor must send a new Add Another PDL request.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        // One Approve: the documents waiting for review and the relationship together.
        try {
            // Approve is also the officer's confirmation (stated in its pop-up). A minor's companion
            // comes from registration; the form only asks when none was given.
            $result = app(\App\Services\RelationshipApproval::class)->approve(
                $relationship, $staffId, \App\Support\Sanitizer::text($request->input('accompanying_adult'))
            );
        } catch (\Throwable $e) {
            Log::error('Relationship approval failed: ' . $e->getMessage());
            return back()->with('error', 'Could not approve this relationship. ' . $e->getMessage());
        }
        if (! $result['approved']) {
            return back()->with('error', 'This relationship cannot be approved yet. Still needed: ' . implode(', ', $result['missing']) . '.');
        }

        $this->logAudit('update', 'visitor_pdl_relationships', $relationship->relationship_id,
            "Approved relationship: {$visitor->full_name} as {$relationship->relationshipLabel()} to PDL #{$relationship->pdl_id}"
            . ($result['documents'] ? " (with {$result['documents']} " . ($result['documents'] === 1 ? 'document' : 'documents') . ')' : ''));

        $pdlName = $relationship->pdl?->full_name ?? 'the PDL';
        $this->notifyAdditionalPdl($visitor, $relationship, 'relationship_approved', 'PDL Relationship Approved',
            "Your relationship to {$pdlName} ({$relationship->relationshipLabel()}) has been approved. "
            . 'You can now select this PDL on the Visitation Calendar when requesting a visit.');

        return back()->with('success', 'Relationship approved.');
    }

    public function rejectRelationship(Request $request, VisitorProfile $visitor, VisitorPdlRelationship $relationship)
    {
        abort_unless($relationship->visitor_id === $visitor->visitor_id, 404);

        // Shown to the visitor in the app — keep it to what they can act on.
        // Not stored on the relationship (no column): it goes in the
        // notification and the audit log.
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'rejection_reason.required' => 'Tell the visitor why this relationship was rejected.',
            'rejection_reason.min' => 'Give a reason of at least 5 characters so the visitor knows what to fix.',
        ]);
        $reason = trim((string) $data['rejection_reason']);

        if ($relationship->verification_status === 'rejected') {
            return back()->with('error', 'This relationship is already rejected.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        // An approved relationship taken back later (e.g. a document found to
        // be false) is a revoked approval, worded as such for staff and visitor.
        $revoking = $relationship->verification_status === 'verified';

        try {
            $relationship->update([
                'verification_status' => 'rejected',
                'verified_by' => $staffId,
                'verified_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Relationship rejection failed: ' . $e->getMessage());
            return back()->with('error', 'Could not reject this relationship. ' . $e->getMessage());
        }

        $this->logAudit('update', 'visitor_pdl_relationships', $relationship->relationship_id,
            ($revoking ? 'Revoked approved relationship' : 'Rejected relationship')
            . ": {$visitor->full_name} as {$relationship->relationshipLabel()} to PDL #{$relationship->pdl_id}: {$reason}");

        // Only this relationship changes: the account and every other PDL stay
        // as they are. A rejected relationship takes no new documents, so the
        // visitor sends a new Add Another PDL request (staff then reopen it).
        // Same notification type either way: the app reads "Reason:" from it
        // and offers "Request Again" (notificationActions.js).
        $pdlName = $relationship->pdl?->full_name ?? 'the PDL';
        $this->notifyAdditionalPdl($visitor, $relationship, 'relationship_rejected',
            $revoking ? 'PDL Relationship Approval Revoked' : 'PDL Relationship Not Approved',
            "Your relationship to {$pdlName} ({$relationship->relationshipLabel()}) "
            . ($revoking ? 'is no longer approved by facility staff' : 'was not approved by facility staff')
            . ". Reason: {$reason}"
            . (preg_match('/[.!?]$/', $reason) ? ' ' : '. ')
            . 'To visit this PDL, send a new Add Another PDL request with corrected documents.');

        return back()->with('success', $revoking ? 'Relationship approval revoked.' : 'Relationship rejected.');
    }

    /**
     * Notifies the visitor about an additional PDL relationship (linked,
     * approved or rejected). Only for an already-approved account: a pending
     * account's first PDL is approved with the account, and that approval
     * already sends its own notification. Each action runs once per change
     * (the actions refuse a repeat), so no duplicate is sent.
     */
    public static function notifyAdditionalPdl(VisitorProfile $visitor, VisitorPdlRelationship $relationship, string $type, string $title, string $message): void
    {
        if ($visitor->verification_status !== 'verified' || ! $visitor->account_id) {
            return;
        }

        try {
            Notification::notify($visitor->account_id, $type, $title, $message,
                'visitor_pdl_relationships', $relationship->relationship_id);
        } catch (\Throwable $e) {
            // The relationship change itself is already saved; a failed
            // notification must not turn it into an error for the officer.
            Log::warning("Relationship notification ({$type}) failed for visitor #{$visitor->visitor_id}: " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // FLAG a visitor (history/behavior tracking)
    // -----------------------------------------------------------------
    public function storeFlag(Request $request, VisitorProfile $visitor)
    {
        $validated = $request->validate([
            'flag_type' => ['required', 'in:' . implode(',', VisitorFlag::TYPES)],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
            'duration' => ['nullable', 'in:' . implode(',', array_keys(VisitorFlag::DURATIONS))],
        ], [
            'description.min' => 'Describe the reason for the flag in at least 10 characters.',
            'duration.in' => 'Choose how long the flag applies.',
        ]);

        // How long the flag applies: N days from now, or until an officer
        // resolves it (also the default when no duration is sent, as before).
        $duration = $validated['duration'] ?? 'until_resolved';
        unset($validated['duration']);
        $validated['expires_at'] = $duration === 'until_resolved' ? null : now()->addDays((int) $duration);

        // One open flag per type is enough; resolve it before adding another.
        if ($visitor->flags()->where('flag_type', $validated['flag_type'])->active()->exists()) {
            return back()->withInput()->withErrors(['flag_type' => 'This visitor already has an active flag of this type.']);
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->withInput()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $flag = VisitorFlag::create([
                ...$validated,
                'visitor_id' => $visitor->visitor_id,
                'flagged_by' => $staffId,
                'status' => 'active',
            ]);
        } catch (\Throwable $e) {
            Log::error('Flagging visitor failed: ' . $e->getMessage());
            return back()->with('error', 'Could not add this flag. ' . $e->getMessage());
        }

        $this->logAudit('create', 'visitor_flags', $flag->flag_id,
            "Flagged {$visitor->full_name}: {$flag->typeLabel()} (" . VisitorFlag::DURATIONS[$duration] . ')');

        // A flagged visitor cannot visit on the dates the flag covers: their
        // upcoming visits on those dates are cancelled and the seats freed.
        $cancelled = app(\App\Services\VisitAssignmentService::class)->cancelVisitsForFlaggedVisitor($visitor);
        $cancelledNote = $cancelled > 0
            ? " {$cancelled} upcoming " . ($cancelled === 1 ? 'visit was' : 'visits were') . ' cancelled.'
            : '';

        return back()->with('success', 'Visitor flagged. ' . $flag->durationLabel() . '.' . $cancelledNote);
    }

    public function resolveFlag(VisitorProfile $visitor, VisitorFlag $flag)
    {
        abort_unless($flag->visitor_id === $visitor->visitor_id, 404);

        if ($flag->status !== 'active') {
            return back()->with('error', 'This flag has already been resolved.');
        }

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

        try {
            $flag->update([
                'status' => 'resolved',
                'resolved_by' => $staffId,
                'resolved_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Resolving flag failed: ' . $e->getMessage());
            return back()->with('error', 'Could not resolve this flag. ' . $e->getMessage());
        }

        $this->logAudit('update', 'visitor_flags', $flag->flag_id,
            "Resolved {$flag->typeLabel()} flag for {$visitor->full_name}");

        return back()->with('success', 'Flag resolved.');
    }
}
