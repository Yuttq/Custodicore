<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class VisitorId extends Model
{
    protected $table = 'visitor_ids';
    protected $primaryKey = 'visitor_id_doc_id';
    public $timestamps = false; // has uploaded_at instead

    /**
     * Government ID files live on the private disk (storage/app/private),
     * never reachable by URL — staff see them only through the role-checked
     * idFile / idPhoto routes. file_path is relative to the disk
     * ("visitor-ids/<random>.jpg"), so the same value works on either disk.
     */
    public const DISK = 'local';

    /**
     * TEMPORARY: where IDs were stored before they moved to the private
     * disk. Read-only fallback until `php artisan visitor-ids:move-to-private`
     * has copied every old file and the originals are deleted — then remove
     * this constant and the fallback in fileDisk().
     */
    public const LEGACY_DISK = 'public';

    const TYPES = [
        'national_id',
        'drivers_license',
        'passport',
        'voters_id',
        'philhealth_id',
        'umid',
    ];

    /** Display names, written the way the IDs are printed. Same order as TYPES. */
    public const TYPE_LABELS = [
        'national_id' => 'National ID',
        'drivers_license' => "Driver's License",
        'passport' => 'Passport',
        'voters_id' => "Voter's ID",
        'philhealth_id' => 'PhilHealth ID',
        'umid' => 'UMID',
    ];

    protected $fillable = [
        'visitor_id',
        'id_type',
        'id_number',
        'file_path',
        'verification_status',
        'reason',
        'rejection_reason',
        'verified_by',
        'verified_at',
        'expiry_date',
        'no_expiry',
    ];

    /** How many days before its expiry date an ID is flagged "expires soon". */
    public const EXPIRES_SOON_DAYS = 30;

    protected $casts = [
        'verified_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'expiry_date' => 'date',
        'no_expiry' => 'boolean',
    ];

    /**
     * The ID's expiry on $date (default today):
     *   unknown  — not recorded yet
     *   none     — the ID has no expiry date
     *   valid    — expires later
     *   soon     — expires within EXPIRES_SOON_DAYS
     *   expired  — its expiry date is before $date
     */
    public function expiryState(\DateTimeInterface|string|null $date = null): string
    {
        if ($this->no_expiry) {
            return 'none';
        }
        if (! $this->expiry_date) {
            return 'unknown';
        }

        $day = \Illuminate\Support\Carbon::parse($date ?? today())->startOfDay();

        return match (true) {
            $this->expiry_date->lt($day) => 'expired',
            $this->expiry_date->lte($day->copy()->addDays(self::EXPIRES_SOON_DAYS)) => 'soon',
            default => 'valid',
        };
    }

    /**
     * Visitors' IDs in use (approved, the visitor's current ID) that have
     * expired or expire within EXPIRES_SOON_DAYS: the ones to renew. With
     * their visitor loaded.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function needingRenewal(): \Illuminate\Support\Collection
    {
        return self::where('verification_status', 'verified')
            ->where('no_expiry', false)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today()->addDays(self::EXPIRES_SOON_DAYS)->toDateString())
            ->with('visitor.account')
            ->get()
            ->filter(fn (self $id) => $id->visitor
                && $id->visitor->currentGovernmentId()?->visitor_id_doc_id === $id->visitor_id_doc_id)
            ->values();
    }

    /** "Expired Mar 4, 2026", "Expires Mar 4, 2028", "No expiry date", "Expiry not recorded". */
    public function expiryLabel(): string
    {
        return match ($this->expiryState()) {
            'none' => 'No expiry date',
            'unknown' => 'Expiry not recorded',
            'expired' => 'Expired ' . $this->expiry_date->format('M j, Y'),
            'soon' => 'Expires ' . $this->expiry_date->format('M j, Y') . ' (' . today()->diffInDays($this->expiry_date) . ' days left)',
            default => 'Expires ' . $this->expiry_date->format('M j, Y'),
        };
    }

    /**
     * A duplicate ID staff set aside (VisitorController::setAsideId). Kept
     * with its file and history, never approved and never shown to the
     * visitor. rejection_reason holds the staff note.
     */
    public const STATUS_SUPERSEDED = 'superseded';

    /** IDs still in play: everything except a set-aside duplicate. */
    public function scopeActive($query)
    {
        return $query->where('verification_status', '!=', self::STATUS_SUPERSEDED);
    }

    public function isSuperseded(): bool
    {
        return $this->verification_status === self::STATUS_SUPERSEDED;
    }

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

    /**
     * The stored key (national_id) for a key or display label ("National
     * ID", "Driver's License", "PhilHealth", ...) as the mobile app sends
     * them; the raw value unchanged when nothing matches, so validation
     * against TYPES still rejects it.
     */
    public static function resolveType(mixed $raw): mixed
    {
        if (! is_string($raw) || in_array($raw, self::TYPES, true)) {
            return $raw;
        }

        $labelToType = [];
        foreach (self::TYPES as $type) {
            $labelToType[strtolower((new self(['id_type' => $type]))->typeLabel())] = $type;
        }
        $labelToType += [
            "driver's license" => 'drivers_license', "voter's id" => 'voters_id',
            'philhealth' => 'philhealth_id', 'philhealth id' => 'philhealth_id', 'umid' => 'umid',
        ];

        return $labelToType[strtolower(trim($raw))] ?? $raw;
    }

    /**
     * The disk holding this ID's file: the private disk, else (temporarily)
     * the legacy public disk for a file not yet moved; null when there is
     * no file at all.
     */
    public function fileDisk(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($this->file_path)) {
                return $disk;
            }
        }

        return null;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->id_type]
            ?? ucwords(str_replace('_', ' ', (string) $this->id_type));
    }
}