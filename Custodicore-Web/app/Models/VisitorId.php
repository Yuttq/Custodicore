<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorId extends Model
{
    protected $table = 'visitor_ids';
    protected $primaryKey = 'visitor_id_doc_id';
    public $timestamps = false; // has uploaded_at instead

    const TYPES = [
        'national_id',
        'drivers_license',
        'passport',
        'voters_id',
        'philhealth_id',
        'umid',
    ];

    protected $fillable = [
        'visitor_id',
        'id_type',
        'id_number',
        'file_path',
        'verification_status',
        'reason',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function visitor()
    {
        return $this->belongsTo(
            VisitorProfile::class,
            'visitor_id',
            'visitor_id'
        );
    }

    public function verifiedBy()
    {
        return $this->belongsTo(
            StaffProfile::class,
            'verified_by',
            'staff_id'
        );
    }

    public function typeLabel(): string
    {
        return ucwords(
            str_replace('_', ' ', $this->id_type)
        );
    }
}