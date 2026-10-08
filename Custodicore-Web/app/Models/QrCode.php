<?php

namespace App\Models;

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
     * Generate (or re-generate) the gate QR for a visit request. Expiry
     * defaults to 180 minutes — matches the `qr.expiry_minutes` system
     * setting seeded in DatabaseSeeder; pass the real setting value in
     * once SystemSetting lookups are wired into a request cycle that has
     * access to it, if this ever needs to move off the hardcoded default.
     */
    public static function generateFor(VisitRequest $visitRequest, int $expiryMinutes = 180): self
    {
        // A visit request has at most one QR (unique visit_request_id) —
        // re-generating replaces rather than duplicates.
        $qr = self::firstOrNew(['visit_request_id' => $visitRequest->visit_request_id]);
        $qr->fill([
            'qr_token' => Str::random(64),
            'expires_at' => now()->addMinutes($expiryMinutes),
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
