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
        'date_of_birth',
        'gender',
        'address',
        'relationship_hint',
        'contact_number',
        'emergency_contact_name',
        'emergency_contact_number',
        'verification_status',
        'verified_by',
        'verified_at',
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

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}