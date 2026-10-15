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
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'verified_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
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

    public function activeFlags()
    {
        return $this->flags()->where('status', 'active');
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