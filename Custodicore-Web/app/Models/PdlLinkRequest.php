<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An approved visitor's request to add another PDL (Phase 5B).
 *
 * Separate from VisitorPdlRelationship until the Record Officer identifies
 * the PDL: the visitor only gives its name. Its documents are
 * relationship_documents rows with pdl_link_request_id set and, until the
 * request is linked, no relationship_id.
 *
 * Statuses: open → linked | rejected | cancelled. While open,
 * open_visitor_id = visitor_id (one open request per visitor, enforced by a
 * unique index); otherwise it is NULL. Use markClosed() when leaving `open`.
 *
 * Document rules for the later workflow stages:
 *   - linked: the request's documents move to the relationship (kept, not deleted);
 *   - rejected or cancelled: the request's documents and their stored files are deleted;
 *   - reopening a rejected relationship: that relationship's old rejected
 *     documents and their files are deleted before the request's documents
 *     are attached.
 */
class PdlLinkRequest extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_LINKED = 'linked';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_LINKED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    protected $primaryKey = 'request_id';

    protected $fillable = [
        'visitor_id',
        'pdl_name',
        'relationship_type',
        'has_children_together',
        'status',
        'open_visitor_id',
        'relationship_id',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'has_children_together' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A new request is open: claim the visitor's single open slot.
        static::creating(function (PdlLinkRequest $request) {
            $request->status ??= self::STATUS_OPEN;
            if ($request->status === self::STATUS_OPEN) {
                $request->open_visitor_id = $request->visitor_id;
            }
        });
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Attributes that close the request and free the visitor's open slot. */
    public static function closingAttributes(string $status): array
    {
        return ['status' => $status, 'open_visitor_id' => null];
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function visitor()
    {
        return $this->belongsTo(VisitorProfile::class, 'visitor_id', 'visitor_id');
    }

    /** The relationship the request was linked to (set once linked). */
    public function relationship()
    {
        return $this->belongsTo(VisitorPdlRelationship::class, 'relationship_id', 'relationship_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(StaffProfile::class, 'reviewed_by', 'staff_id');
    }

    /** Every file sent with this request (also after it moved to a relationship). */
    public function documents()
    {
        return $this->hasMany(RelationshipDocument::class, 'pdl_link_request_id', 'request_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('document_id');
    }

    public function relationshipLabel(): string
    {
        return VisitorPdlRelationship::TYPE_LABELS[$this->relationship_type] ?? $this->relationship_type;
    }
}
