<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorProfile extends Model
{
    protected $table = 'visitor_profiles';
    protected $primaryKey = 'visitor_id';

    protected $fillable = [
        'account_id',
        'full_name',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'address',
        'relationship_hint',
        'intended_pdl_name',
        'intended_pdl_birth_date',
        'intended_relationship_type',
        'intended_pdl_number',
        'guardian_full_name',
        'guardian_relationship',
        'guardian_contact_number',
        'contact_number',
        'emergency_contact_name',
        'emergency_contact_number',
        'verification_status',
        'verified_by',
        'verified_at',
        'rejection_reason',
        'photo_path',
        'photo_uploaded_at',
        'photo_status',
        'photo_rejection_reason',
        'photo_reviewed_by',
        'photo_reviewed_at',
        'registered_via',
        'walk_in_registered_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'intended_pdl_birth_date' => 'date',
        'verified_at' => 'datetime',
        'photo_uploaded_at' => 'datetime',
        'photo_reviewed_at' => 'datetime',
    ];

    /** The photo's own review (photo_status), like a government ID's. */
    public const PHOTO_PENDING = 'pending';

    public const PHOTO_VERIFIED = 'verified';

    public const PHOTO_REJECTED = 'rejected';

    /** Registered at the Front Desk (no phone), not through the mobile app. */
    public function isWalkIn(): bool
    {
        return $this->registered_via === 'walk_in';
    }

    /**
     * A walk-in who has never signed in to the app (no phone, or never
     * claimed the account): they cannot confirm a visit there, so a visit
     * staff assign to them is confirmed at once (VisitAssignmentService).
     */
    public function usesNoApp(): bool
    {
        return $this->isWalkIn() && $this->account?->last_login_at === null;
    }

    /** Visitor photo rules (mobile upload): a real JPG/PNG/WEBP picture, up to 5 MB. */
    public const PHOTO_RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    /** Staff-only link to the visitor's photo, or null when none was taken yet. */
    public function photoUrl(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        // ?v= changes with the file so a retaken photo isn't served from cache.
        return route('visitor.photo', $this->visitor_id) . '?v=' . substr(md5($this->photo_path), 0, 8);
    }

    public function initial(): string
    {
        return strtoupper(mb_substr(trim((string) $this->full_name), 0, 1)) ?: '?';
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** Walk-in arrivals with the Front Desk assessment, newest first. */
    public function walkInAssessments()
    {
        return $this->hasMany(WalkInAssessment::class, 'visitor_id', 'visitor_id')->latest('assessment_id');
    }

    /** The Front Desk officer who registered this walk-in. */
    public function walkInRegisteredBy()
    {
        return $this->belongsTo(StaffProfile::class, 'walk_in_registered_by', 'staff_id');
    }

    public function relationships()
    {
        return $this->hasMany(VisitorPdlRelationship::class, 'visitor_id', 'visitor_id');
    }

    public function idDocuments()
    {
        return $this->hasMany(VisitorId::class, 'visitor_id', 'visitor_id');
    }

    /**
     * Relationship documents sent with the registration that are not attached
     * to a staff-linked relationship yet (newest first). Files sent with a
     * request to add another PDL are not included (see pdlLinkRequests).
     */
    public function registrationDocuments()
    {
        return $this->hasMany(RelationshipDocument::class, 'visitor_id', 'visitor_id')
            ->registrationUploads()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('document_id');
    }

    /** The visitor's requests to add another PDL (Phase 5B), newest first. */
    public function pdlLinkRequests()
    {
        return $this->hasMany(PdlLinkRequest::class, 'visitor_id', 'visitor_id')
            ->orderByDesc('created_at')
            ->orderByDesc('request_id');
    }

    public function flags()
    {
        return $this->hasMany(VisitorFlag::class, 'visitor_id', 'visitor_id');
    }

    /**
     * The active flag that lasts longest ("until resolved" beats any date),
     * or null when the visitor has no active flag. Uses the loaded flags when present.
     */
    public function longestActiveFlag(): ?VisitorFlag
    {
        $flags = $this->relationLoaded('flags') ? $this->flags->filter->isActive() : $this->activeFlags()->get();

        return $flags->sortByDesc(fn (VisitorFlag $f) => $f->expires_at?->timestamp ?? PHP_INT_MAX)->first();
    }

    /** Visitors not registered at the Front Desk as walk-ins (the desk approves those). */
    public function scopeNotWalkIn($query)
    {
        return $query->where(fn ($q) => $q->where('registered_via', '!=', 'walk_in')->orWhereNull('registered_via'));
    }

    public function activeFlags()
    {
        // Not resolved and not past its time limit (VisitorFlag::scopeActive).
        return $this->flags()->active();
    }

    public function visitRequests()
    {
        return $this->hasMany(VisitRequest::class, 'visitor_id', 'visitor_id');
    }

    /** Guardian Information given at registration (any of the three fields). */
    public function hasGuardianDetails(): bool
    {
        return filled($this->guardian_full_name)
            || filled($this->guardian_relationship)
            || filled($this->guardian_contact_number);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Whether the visitor may take or retake their own photo from the app
     * (POST /api/me/photo): only when there is none yet, or staff rejected it
     * (or asked for a new one). A photo waiting for review or approved stays
     * locked; returning the whole account does not unlock it. Saving a new
     * photo puts it back to pending, which locks it again.
     */
    public function canChangePhoto(): bool
    {
        return ! $this->photo_path || $this->photo_status === self::PHOTO_REJECTED;
    }

    /**
     * The visitor's current government ID: the newest one still in play
     * (set-aside duplicates excluded), by upload time, then by id for the
     * same second. Replacement and booking rules look at this ID only;
     * older IDs stay unchanged as history.
     */
    public function currentGovernmentId(): ?VisitorId
    {
        return $this->idDocuments()->active()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('visitor_id_doc_id')
            ->first();
    }

    /**
     * Whether the visitor may upload a government ID from the app
     * (POST /api/documents): only when they have none yet, or staff rejected
     * their current (newest) ID or asked for a new one. Never while it is
     * waiting for review or approved, for approved accounts too; returning
     * the whole account does not unlock it. An older approved ID does not
     * prevent replacing the current one.
     */
    public function canUploadGovernmentId(): bool
    {
        $current = $this->currentGovernmentId();

        return ! $current || $current->verification_status === 'rejected';
    }

    /** Officer who last rejected or verified the photo. */
    public function photoReviewedBy()
    {
        return $this->belongsTo(StaffProfile::class, 'photo_reviewed_by', 'staff_id');
    }

    /** Government IDs waiting for review (set-aside duplicates excluded). */
    public function pendingIdCount(): int
    {
        return $this->idDocuments()->where('verification_status', 'pending')->count();
    }

    /**
     * Every item's review state, read from the database (never from cached
     * relations), as visitor-facing labels:
     *   needsReplacement — rejected by staff (or staff asked for a new copy)
     *                      and nothing newer sent yet: the visitor must replace it
     *   awaitingReview   — approved accounts only: a replacement the visitor sent
     *                      that staff have not accepted yet (Accept replacements)
     *
     * Items: the photo; the current (newest) government ID — older IDs are
     * history; each registration document and each file of a verified relationship
     * (of a pending one too while the account is not approved), by newest
     * file per requirement. Files of a PDL-link request or a rejected
     * relationship do not count.
     *
     * @return array{needsReplacement: list<string>, awaitingReview: list<string>, awaitingDocumentIds: list<int>}
     */
    public function itemReviewState(): array
    {
        $needs = [];
        $awaiting = [];
        $awaitingDocumentIds = [];
        $approved = $this->isVerified();

        if ($this->photo_path && $this->photo_status === self::PHOTO_REJECTED) {
            $needs[] = 'Visitor Photo';
        } elseif ($approved && $this->photo_path && $this->photo_status === self::PHOTO_PENDING && filled($this->photo_rejection_reason)) {
            // A retake after staff asked for a new one (the reason is kept until it is verified).
            $awaiting[] = 'Visitor Photo';
        }

        // The current (newest) ID decides; an older approved ID does not cover a
        // newer one staff rejected or asked to replace.
        $currentId = $this->currentGovernmentId();
        if ($currentId?->verification_status === 'rejected') {
            $needs[] = 'Government ID';
        } elseif ($approved && $currentId?->verification_status === 'pending'
            && $this->idDocuments()->active()->where('verification_status', 'rejected')->exists()) {
            // A new ID sent after staff rejected one (or asked for a new one).
            $awaiting[] = 'Government ID';
        }

        $relationshipStatuses = $approved ? ['verified'] : ['verified', 'pending'];
        $documents = RelationshipDocument::where('visitor_id', $this->visitor_id)
            ->whereNull('pdl_link_request_id')
            ->where(fn ($q) => $q->whereNull('relationship_id')
                ->orWhereHas('relationship', fn ($r) => $r->whereIn('verification_status', $relationshipStatuses)))
            ->orderByDesc('uploaded_at')
            ->orderByDesc('document_id')
            ->get();

        foreach ($documents->groupBy(fn ($d) => ($d->relationship_id ?? 'registration') . '|' . $d->requirement_key) as $slot) {
            $newest = $slot->first();
            if ($newest->status === 'rejected') {
                $needs[] = $newest->label();
            } elseif ($approved && $newest->status === 'pending' && $slot->contains('status', 'rejected')) {
                $awaiting[] = $newest->label();
                $awaitingDocumentIds[] = (int) $newest->document_id;
            }
        }

        return [
            'needsReplacement' => array_values(array_unique($needs)),
            'awaitingReview' => array_values(array_unique($awaiting)),
            'awaitingDocumentIds' => $awaitingDocumentIds,
        ];
    }

    /**
     * Why new visits cannot be booked for an approved visitor right now:
     * items to replace and replacements staff have not accepted yet. Empty
     * when bookings are open (and for accounts not approved, which the
     * approval checks already block).
     *
     * @return list<string>
     */
    public function bookingBlockers(): array
    {
        if (! $this->isVerified()) {
            return [];
        }
        $state = $this->itemReviewState();

        return array_values(array_unique([...$state['needsReplacement'], ...$state['awaitingReview']]));
    }

    /**
     * Verifies a photo still waiting for review (final Approve, Accept
     * replacements, a walk-in approved at the desk). Conditional update: a
     * rejected or already verified photo is never changed. Returns whether
     * it was verified.
     */
    public function verifyPendingPhoto(int $staffId, string $auditDescription): bool
    {
        $now = now();
        $values = [
            'photo_status' => self::PHOTO_VERIFIED,
            'photo_rejection_reason' => null,
            'photo_reviewed_by' => $staffId,
            'photo_reviewed_at' => $now,
        ];
        $verified = static::whereKey($this->visitor_id)
            ->whereNotNull('photo_path')
            ->where('photo_status', self::PHOTO_PENDING)
            ->update($values) > 0;

        if ($verified) {
            $this->forceFill($values);
            $this->syncOriginalAttributes(array_keys($values));
            AuditLog::record('update', 'visitor_profiles', $this->visitor_id, $auditDescription, Module::CODE_VISITOR_MANAGEMENT);
        }

        return $verified;
    }

    /**
     * A returned account (stored as 'rejected') goes back to Account Review
     * ('pending') once the visitor has saved a correction: changed profile
     * details, a new government ID, photo or document. Call it only after
     * the correction is saved. Nothing is approved here; the officer reviews
     * the account again. rejection_reason is kept so the officer can see what
     * was asked for; approving clears it, as before.
     *
     * Conditional update, so two corrections at once log it only once.
     * Returns whether the account was moved back.
     */
    public function resubmitIfReturned(string $correction): bool
    {
        if ($this->verification_status !== 'rejected') {
            return false;
        }

        $moved = static::whereKey($this->visitor_id)
            ->where('verification_status', 'rejected')
            // A correction also reopens a closed return and resets its reminder (ReturnedAccounts).
            ->update(['verification_status' => 'pending', 'updated_at' => now(),
                'return_reminded_at' => null, 'return_closed_at' => null, 'return_closed_by' => null]) > 0;

        if ($moved) {
            $this->verification_status = 'pending';
            $this->syncOriginalAttribute('verification_status');
            try {
                AuditLog::record('update', 'visitor_profiles', $this->visitor_id,
                    "Visitor corrected their returned account via mobile app ({$correction}); back in Account Review",
                    Module::CODE_VISITOR_MANAGEMENT);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Audit log write failed: ' . $e->getMessage());
            }
        }

        return $moved;
    }

    /** Approved visitor with replacements staff have not accepted yet (Review Queue → Accounts). */
    public function scopeWithReplacementsAwaitingReview($query)
    {
        // Mirrors itemReviewState()['awaitingReview']; the page re-checks each card.
        return $query->where('verification_status', 'verified')->where(function ($q) {
            $q->where(fn ($p) => $p->whereNotNull('photo_path')->where('photo_status', self::PHOTO_PENDING)->whereNotNull('photo_rejection_reason'))
                // A new ID sent after one was rejected / asked to replace (the page
                // re-checks that it is the current, newest ID).
                ->orWhere(fn ($i) => $i->whereHas('idDocuments', fn ($d) => $d->where('verification_status', 'pending'))
                    ->whereHas('idDocuments', fn ($d) => $d->where('verification_status', 'rejected')))
                ->orWhereExists(function ($e) {
                    $e->selectRaw('1')->from('relationship_documents as pending_doc')
                        ->whereColumn('pending_doc.visitor_id', 'visitor_profiles.visitor_id')
                        ->where('pending_doc.status', 'pending')
                        ->whereNull('pending_doc.pdl_link_request_id')
                        ->where(fn ($r) => $r->whereNull('pending_doc.relationship_id')
                            ->orWhereExists(fn ($v) => $v->selectRaw('1')->from('visitor_pdl_relationships as rel')
                                ->whereColumn('rel.relationship_id', 'pending_doc.relationship_id')
                                ->where('rel.verification_status', 'verified')))
                        ->whereExists(fn ($old) => $old->selectRaw('1')->from('relationship_documents as old_doc')
                            ->whereColumn('old_doc.visitor_id', 'pending_doc.visitor_id')
                            ->whereColumn('old_doc.requirement_key', 'pending_doc.requirement_key')
                            ->where('old_doc.status', 'rejected')
                            ->whereColumn('old_doc.document_id', '<', 'pending_doc.document_id')
                            ->where(fn ($same) => $same->whereColumn('old_doc.relationship_id', 'pending_doc.relationship_id')
                                ->orWhere(fn ($both) => $both->whereNull('old_doc.relationship_id')->whereNull('pending_doc.relationship_id'))));
                });
        });
    }

    /** At least one government ID checked and verified by staff. Required before approving the account. */
    public function hasVerifiedId(): bool
    {
        return $this->relationLoaded('idDocuments')
            ? $this->idDocuments->contains('verification_status', 'verified')
            : $this->idDocuments()->where('verification_status', 'verified')->exists();
    }
}