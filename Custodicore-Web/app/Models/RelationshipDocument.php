<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A file a visitor uploaded for one BJMP requirement of a visitor↔PDL
// relationship (marriage certificate, CENOMAR, ...). Stored on the private
// `local` disk; staff open it through RelationshipRequirementController::file.
//
// Files sent with the registration have no relationship yet
// (relationship_id NULL, visitor_id set). They are attached when staff link
// the PDL — see VisitorPdlRelationship::attachRegistrationDocuments().
class RelationshipDocument extends Model
{
    protected $table = 'relationship_documents';
    protected $primaryKey = 'document_id';
    public $timestamps = false; // only uploaded_at

    protected $fillable = [
        'relationship_id',
        'visitor_id',
        'requirement_key',
        'file_path',
        'original_name',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'uploaded_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function relationship()
    {
        return $this->belongsTo(VisitorPdlRelationship::class, 'relationship_id', 'relationship_id');
    }

    public function visitor()
    {
        return $this->belongsTo(VisitorProfile::class, 'visitor_id', 'visitor_id');
    }

    public function label(): string
    {
        return \App\Services\RelationshipRequirements::DOCUMENTS[$this->requirement_key][0]
            ?? ucwords(str_replace('_', ' ', $this->requirement_key));
    }

    public function reviewedBy()
    {
        return $this->belongsTo(StaffProfile::class, 'reviewed_by', 'staff_id');
    }
}
