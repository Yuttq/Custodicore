<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// CONFIRMED against the actual migration (2024_01_02_000080_create_pdl_profiles_table.php):
// registered_by -> staff_profiles.staff_id (not accounts, as earlier guessed).
//
// IMPORTANT — not yet safe to use: auth() currently authenticates against
// Laravel's default `users` table (see App\Models\User's own doc comment),
// which has no relationship to staff_profiles at all. Any code that does
// 'registered_by' => auth()->id() will insert a users.id into a column
// with a foreign key pointing at staff_profiles — that will fail its FK
// constraint (or silently insert garbage if the constraint isn't enforced).
// This needs real auth wired to accounts/staff_profiles before these writes
// are safe to run — see PdlController for where this is flagged inline.

class Pdl extends Model
{
    protected $table = 'pdl_profiles';
    protected $primaryKey = 'pdl_id';

    protected $fillable = [
        'pdl_number',
        'full_name',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'alias',
        'date_of_birth',
        'gender',
        'classification',
        'cell_block',
        'admission_date',
        'custody_status',
        'photo_path',
        'registered_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
        'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // full_name is what every list, search and mobile screen reads, so
        // keep it in sync whenever the separate name parts are set.
        static::saving(function (Pdl $pdl) {
            if ($pdl->first_name !== null || $pdl->last_name !== null) {
                $pdl->full_name = self::composeFullName($pdl->first_name, $pdl->middle_name, $pdl->last_name, $pdl->suffix);
            }

            // When the PDL was released ("Released YTD" counts from it);
            // cleared when they are re-admitted or their status changes again.
            if ($pdl->isDirty('custody_status')) {
                $pdl->released_at = $pdl->custody_status === 'released' ? now() : null;
            }
        });

        // Leaving active custody cancels the PDL's upcoming visits.
        static::updated(function (Pdl $pdl) {
            if ($pdl->wasChanged('custody_status') && $pdl->custody_status !== 'active') {
                app(\App\Services\VisitAssignmentService::class)->cancelVisitsForUnavailablePdl(
                    $pdl,
                    "custody status changed to {$pdl->custody_status}"
                );
            }
        });
    }

    /** "Juan Santos Dela Cruz Jr." — the suffix (optional) goes last. */
    public static function composeFullName(?string $first, ?string $middle, ?string $last, ?string $suffix = null): string
    {
        return implode(' ', array_filter(array_map(fn ($part) => trim((string) $part), [$first, $middle, $last, $suffix]), fn ($part) => $part !== ''));
    }

    /**
     * "Dela Cruz, Juan Santos Jr." — last name first, the form used on the
     * staff screens. Older PDLs with only a full name on file show it as is.
     */
    public function listName(): string
    {
        if ($this->last_name === null && $this->first_name === null) {
            return (string) $this->full_name;
        }

        $given = trim(implode(' ', array_filter([$this->first_name, $this->middle_name])));
        $name = $this->last_name !== null && $this->last_name !== '' ? $this->last_name . ($given !== '' ? ', ' . $given : '') : $given;

        return $this->suffix ? $name . ' ' . $this->suffix : $name;
    }

    /**
     * Another PDL who looks like the same person: same full name (ignoring
     * case) and same date of birth. $exceptId leaves out the PDL being edited.
     */
    public static function findSamePerson(string $fullName, string $dateOfBirth, ?int $exceptId = null): ?self
    {
        return self::whereRaw('LOWER(full_name) = ?', [mb_strtolower($fullName)])
            ->whereDate('date_of_birth', $dateOfBirth)
            ->when($exceptId, fn ($q) => $q->where('pdl_id', '!=', $exceptId))
            ->orderBy('pdl_id')
            ->first();
    }

    /**
     * Why a PDL who left custody cannot be visited, in plain words, or null
     * while in active custody. Shown wherever a visit is refused, cancelled
     * or stopped at the gate for that reason.
     */
    public function custodyMessage(): ?string
    {
        $phrase = $this->leftCustodyPhrase();
        if ($phrase === null) {
            return null;
        }

        return $this->custody_status === 'deceased'
            ? "This PDL {$phrase}. Visits are no longer possible."
            : "This PDL {$phrase}, so visits can no longer be booked.";
    }

    /** "has been released from custody", ...; null while in active custody. */
    public function leftCustodyPhrase(): ?string
    {
        return match ($this->custody_status) {
            'active' => null,
            'released' => 'has been released from custody',
            'transferred' => 'has been transferred to another facility',
            'deceased' => 'is recorded as deceased',
            default => 'is no longer in custody',
        };
    }

    /** "Released from custody", "Transferred to another facility", "Deceased" — for badges. */
    public function custodyLabel(): string
    {
        return match ($this->custody_status) {
            'active' => 'In custody',
            'released' => 'Released from custody',
            'transferred' => 'Transferred to another facility',
            'deceased' => 'Deceased',
            default => ucfirst((string) $this->custody_status),
        };
    }

    /** Released or transferred: the PDL can be taken back into custody on the same record. */
    public function canBeReadmitted(): bool
    {
        return in_array($this->custody_status, ['released', 'transferred'], true);
    }

    /**
     * First/middle/last for the edit form. Older PDLs only have full_name,
     * so this falls back to a guess (first word / last word / the rest in
     * the middle) for the officer to check before saving.
     */
    public function nameParts(): array
    {
        if ($this->first_name !== null || $this->last_name !== null) {
            return ['first' => $this->first_name, 'middle' => $this->middle_name, 'last' => $this->last_name, 'suffix' => $this->suffix];
        }

        $words = preg_split('/\s+/', trim((string) $this->full_name), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) {
            return ['first' => $words[0] ?? '', 'middle' => null, 'last' => '', 'suffix' => null];
        }

        // A suffix in an old full name stays in the guessed last name for
        // the officer to move; guessing here could drop it on save.
        return [
            'first' => array_shift($words),
            'last' => array_pop($words),
            'middle' => $words ? implode(' ', $words) : null,
            'suffix' => null,
        ];
    }

    // --- Relationships ------------------------------------------------

    public function legalRecords()
    {
        return $this->hasMany(PdlLegalRecord::class, 'pdl_id', 'pdl_id')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Classification follows the PDL's cases: drug-related if any case is a
     * drug offense (RA 9165), otherwise non-drug-related. A PDL with no case
     * on file keeps whatever it had. Returns true if it changed.
     */
    public function refreshClassification(): bool
    {
        $records = $this->legalRecords()->get();
        if ($records->isEmpty()) {
            return false;
        }

        $classification = $records->contains(fn (PdlLegalRecord $r) => $r->isDrugRelated()) ? 'drug_related' : 'non_drug_related';
        if ($classification === $this->classification) {
            return false;
        }

        $this->update(['classification' => $classification]);

        return true;
    }

    /** Case groups for analytics, in display order. */
    public const CASE_GROUPS = [
        'drug_only' => 'Drug cases only',
        'non_drug_only' => 'Non-drug cases only',
        'both' => 'Both drug and non-drug cases',
    ];

    /**
     * Which analytics group the PDL's cases put them in: drug cases only,
     * non-drug cases only, or both. Every case on file counts (the same
     * cases the classification uses), so drug_only + both = drug-related.
     * A PDL with no case on file falls back on their classification, so
     * every PDL is in exactly one group.
     */
    public function caseGroup(): string
    {
        $records = $this->relationLoaded('legalRecords') ? $this->legalRecords : $this->legalRecords()->get();

        if ($records->isEmpty()) {
            return $this->classification === 'drug_related' ? 'drug_only' : 'non_drug_only';
        }

        $drug = $records->contains(fn (PdlLegalRecord $r) => $r->isDrugRelated());
        $nonDrug = $records->contains(fn (PdlLegalRecord $r) => ! $r->isDrugRelated());

        return match (true) {
            $drug && $nonDrug => 'both',
            $drug => 'drug_only',
            default => 'non_drug_only',
        };
    }

    /**
     * Case group of every PDL in custody, keyed by pdl_id.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function caseGroupsInCustody(): \Illuminate\Support\Collection
    {
        return self::where('custody_status', 'active')
            ->with('legalRecords:legal_record_id,pdl_id,offense_code,offense')
            ->get(['pdl_id', 'classification'])
            ->mapWithKeys(fn (Pdl $pdl) => [$pdl->pdl_id => $pdl->caseGroup()]);
    }

    /** Offense of the first case on file (the one recorded at registration), for lists and headers. */
    public function primaryOffense(): ?string
    {
        $first = $this->relationLoaded('legalRecords')
            ? $this->legalRecords->sortBy('legal_record_id')->first()
            : $this->legalRecords()->reorder('legal_record_id')->first();

        return $first?->offense;
    }

    public function classificationLabel(): string
    {
        return match ($this->classification) {
            'drug_related' => 'Drug-related',
            'non_drug_related' => 'Non-drug-related',
            default => '—',
        };
    }

    public function disciplinaryRecords()
    {
        return $this->hasMany(PdlDisciplinaryRecord::class, 'pdl_id', 'pdl_id')
            ->orderBy('incident_date', 'desc');
    }

    public function restrictions()
    {
        return $this->hasMany(PdlRestriction::class, 'pdl_id', 'pdl_id')
            ->orderBy('created_at', 'desc');
    }

    /** Restrictions in effect today: active and dated to cover today. */
    public function activeRestrictions()
    {
        return $this->restrictionsOn(today());
    }

    /** Restrictions in effect on $date: active and dated to cover it. */
    public function restrictionsOn(\DateTimeInterface|string $date)
    {
        return $this->restrictions()->inEffectOn($date);
    }

    /**
     * Whether a visit with this PDL may take place on $date: in active
     * custody and no restriction covering that date.
     */
    public function isVisitableOn(\DateTimeInterface|string $date): bool
    {
        if ($this->custody_status !== 'active') {
            return false;
        }

        return $this->relationLoaded('restrictions')
            ? ! $this->restrictions->contains(fn (PdlRestriction $r) => $r->coversDate($date))
            : ! $this->restrictionsOn($date)->exists();
    }

    public function visitorRelationships()
    {
        return $this->hasMany(VisitorPdlRelationship::class, 'pdl_id', 'pdl_id');
    }

    public function visitRequests()
    {
        return $this->hasMany(VisitRequest::class, 'pdl_id', 'pdl_id');
    }

    public function registeredBy()
    {
        // Corrected: registered_by references staff_profiles.staff_id per
        // the actual migration, not accounts.account_id.
        return $this->belongsTo(StaffProfile::class, 'registered_by', 'staff_id');
    }

    // --- Convenience accessors -----------------------------------------

    /** URL of the PDL's photo, or null when none was uploaded (show initials). */
    public function photoUrl(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        // ?v= changes with the file, so a replaced photo isn't served from cache.
        return route('pdl.photo', $this->pdl_id) . '?v=' . substr(md5($this->photo_path), 0, 8);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->full_name));
        $first = $parts[0][0] ?? '';
        $last = count($parts) > 1 ? ($parts[count($parts) - 1][0] ?? '') : '';
        return strtoupper($first . $last);
    }
}
