<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorPdlRelationship extends Model
{
    protected $table = 'visitor_pdl_relationships';
    protected $primaryKey = 'relationship_id';
    public $timestamps = false; // only has created_at, no updated_at

    // Specific types the Record Officer can choose (BJMP rules). Friends and
    // non-relatives are deliberately not offered — only direct ties may
    // visit; extended relatives need official authorization.
    const RELATIONSHIP_TYPES = [
        'spouse', 'live_in_partner', 'parent', 'child', 'sibling',
        'legal_guardian', 'extended_relative', 'legal_counsel',
    ];

    // Old generic values still on some records. They can't be verified
    // until the officer reclassifies them (see RelationshipRequirements).
    const LEGACY_TYPES = ['immediate_family', 'unknown_or_other'];

    const TYPE_LABELS = [
        'spouse' => 'Spouse',
        'live_in_partner' => 'Live-in Partner',
        'parent' => 'Parent',
        'child' => 'Child',
        'sibling' => 'Sibling',
        'legal_guardian' => 'Legal Guardian',
        'extended_relative' => 'Extended Relative',
        'legal_counsel' => 'Legal Counsel',
        'immediate_family' => 'Immediate Family (needs reclassifying)',
        'unknown_or_other' => 'Other (needs reclassifying)',
    ];

    // BJMP gives priority to spouse, parents and legal guardian.
    const HIGH_PRIORITY_TYPES = ['spouse', 'parent', 'legal_guardian'];

    const PRIORITY_TIERS = ['high_priority', 'requires_verification'];

    protected $fillable = [
        'visitor_id',
        'pdl_id',
        'relationship_type',
        'priority_tier',
        'verification_status',
        'supporting_document_path',
        'has_children_together',
        'officer_confirmations',
        'verified_by',
        'verified_at',
    ];

    const CREATED_AT = 'created_at';

    protected $casts = [
        'has_children_together' => 'boolean',
        'officer_confirmations' => 'array',
    ];

    public static function priorityTierFor(string $type): string
    {
        return in_array($type, self::HIGH_PRIORITY_TYPES, true) ? 'high_priority' : 'requires_verification';
    }

    public function documents()
    {
        return $this->hasMany(RelationshipDocument::class, 'relationship_id', 'relationship_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('document_id');
    }

    /** Newest upload for a requirement — the one that counts. */
    public function latestDocument(string $requirementKey): ?RelationshipDocument
    {
        return $this->documents->firstWhere('requirement_key', $requirementKey);
    }

    public function isLegacyType(): bool
    {
        return in_array($this->relationship_type, self::LEGACY_TYPES, true);
    }

    public function confirmation(string $key): ?array
    {
        return ($this->officer_confirmations ?? [])[$key] ?? null;
    }

    public function visitor()
    {
        return $this->belongsTo(VisitorProfile::class, 'visitor_id', 'visitor_id');
    }

    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function isHighPriority(): bool
    {
        return $this->priority_tier === 'high_priority';
    }

    // "Spouse", "Live-in Partner", etc. instead of the raw value
    public function relationshipLabel(): string
    {
        return self::TYPE_LABELS[$this->relationship_type] ?? ucwords(str_replace('_', ' ', $this->relationship_type));
    }
}
