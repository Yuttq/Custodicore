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
        'registered_via',
        'walk_in_registered_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'verified_at' => 'datetime',
        'photo_uploaded_at' => 'datetime',
    ];

    /** Registered at the Front Desk (no phone), not through the mobile app. */
    public function isWalkIn(): bool
    {
        return $this->registered_via === 'walk_in';
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
     * to a staff-linked relationship yet (newest first).
     */
    public function registrationDocuments()
    {
        return $this->hasMany(RelationshipDocument::class, 'visitor_id', 'visitor_id')
            ->whereNull('relationship_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('document_id');
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

    /** At least one government ID checked and verified by staff. Required before approving the account. */
    public function hasVerifiedId(): bool
    {
        return $this->relationLoaded('idDocuments')
            ? $this->idDocuments->contains('verification_status', 'verified')
            : $this->idDocuments()->where('verification_status', 'verified')->exists();
    }
}