<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorPdlRelationship extends Model
{
    protected $table = 'visitor_pdl_relationships';
    protected $primaryKey = 'relationship_id';
    public $timestamps = false; // only has created_at, no updated_at

    protected $fillable = [
        'visitor_id',
        'pdl_id',
        'relationship_type',
        'priority_tier',
        'verification_status',
        'supporting_document_path',
        'verified_by',
        'verified_at',
    ];

    const CREATED_AT = 'created_at';

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

    // "Spouse", "Father", etc. instead of the raw enum value
    public function relationshipLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->relationship_type));
    }
}
