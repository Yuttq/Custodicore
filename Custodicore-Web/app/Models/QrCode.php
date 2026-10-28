<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QrCode extends Model
{
    protected $table = 'qr_codes';
    protected $primaryKey = 'qr_code_id';
    public $timestamps = false; // has generated_at instead

    protected $fillable = [
        'visit_request_id',
        'qr_token',
        'generated_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id', 'visit_request_id');
    }

    public function checkin()
    {
        return $this->hasOne(VisitCheckin::class, 'qr_code_id', 'qr_code_id');
    }

    public function isValid(): bool
    {
        return $this->status === 'active' && (! $this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * When a visit's pass stops being valid: the end of the visit's last
     * session on its date (Manila time) — 11:30 for a morning visit, 16:30
     * for an afternoon or whole-day one. The gate still checks the date,
     * the session and the visit status on every scan.
     */
    public static function validUntil(VisitRequest $visitRequest): Carbon
    {
        $last = $visitRequest->sessionSchedules()->get()->last() ?? $visitRequest->schedule;

        // No schedule: the gate refuses the visit anyway.
        return $last
            ? Carbon::parse($last->schedule_date->toDateString().' '.$last->time_slot_end)
            : now()->endOfDay();
    }

    /**
     * Generate (or re-generate) the gate QR for a visit request, valid
     * until the end of the visit (see validUntil).
     */
    public static function generateFor(VisitRequest $visitRequest): self
    {
        // A visit request has at most one QR (unique visit_request_id) —
        // re-generating replaces rather than duplicates.
        $qr = self::firstOrNew(['visit_request_id' => $visitRequest->visit_request_id]);
        $qr->fill([
            'qr_token' => Str::random(64),
            'expires_at' => self::validUntil($visitRequest),
            'status' => 'active',
        ]);

        // generated_at is when the pass was first issued (the timeline's
        // "QR Generated" event); a token refresh does not move it.
        if (! $qr->exists) {
            $qr->generated_at = now();
        }

        $qr->save();

        return $qr;
    }
}
