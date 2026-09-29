<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitCheckin extends Model
{
    protected $table = 'visit_checkins';
    protected $primaryKey = 'checkin_id';
    public $timestamps = false; // no created_at/updated_at on this table

    protected $fillable = [
        'visit_request_id',
        'qr_code_id',
        'id_surrendered_type',
        'id_surrender_time',
        'check_in_time',
        'check_in_officer_id',
        'check_out_time',
        'check_out_officer_id',
        'id_returned_time',
        'verification_method',
        'override_reason',
        'status',
    ];

    protected $casts = [
        'id_surrender_time' => 'datetime',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'id_returned_time' => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id', 'visit_request_id');
    }

    public function qrCode()
    {
        return $this->belongsTo(QrCode::class, 'qr_code_id', 'qr_code_id');
    }

    public function checkInOfficer()
    {
        return $this->belongsTo(StaffProfile::class, 'check_in_officer_id', 'staff_id');
    }

    public function checkOutOfficer()
    {
        return $this->belongsTo(StaffProfile::class, 'check_out_officer_id', 'staff_id');
    }

    public function isCheckedIn(): bool
    {
        return $this->status === 'checked_in';
    }
}
