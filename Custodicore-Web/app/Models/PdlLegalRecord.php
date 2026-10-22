<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdlLegalRecord extends Model
{
    protected $table = 'pdl_legal_records';
    protected $primaryKey = 'legal_record_id';
    /**
     * Case statuses, stored as these labels. Convicted and on appeal carry
     * the conviction date and sentence; acquitted/dismissed cases are closed.
     */
    public const PENDING = 'Pending trial';
    public const CONVICTED = 'Convicted';
    public const ON_APPEAL = 'Convicted, on appeal';
    public const ACQUITTED = 'Acquitted';
    public const DISMISSED = 'Dismissed';
    public const STATUSES = [self::PENDING, self::CONVICTED, self::ON_APPEAL, self::ACQUITTED, self::DISMISSED];
    public const CONVICTED_STATUSES = [self::CONVICTED, self::ON_APPEAL];
    public const CLOSED_STATUSES = [self::ACQUITTED, self::DISMISSED];

    protected $fillable = [
        'pdl_id',
        'case_number',
        'offense_code',
        'offense',
        'court',
        'date_filed',
        'case_status',
        'convicted_on',
        'sentence_years',
        'sentence_months',
        'sentence_days',
        'remarks',
        'recorded_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'date_filed' => 'date',
        'convicted_on' => 'date',
        'sentence_years' => 'integer',
        'sentence_months' => 'integer',
        'sentence_days' => 'integer',
    ];

    public function hearings()
    {
        return $this->hasMany(CourtHearing::class, 'legal_record_id', 'legal_record_id')->orderBy('hearing_at');
    }

    /** The next hearing from today on, if one is set. */
    public function nextHearing(): ?CourtHearing
    {
        return $this->hearings->first(fn (CourtHearing $h) => $h->hearing_at->gte(now()->startOfDay()));
    }

    /** Hearings before today, most recent first. */
    public function pastHearings()
    {
        return $this->hearings->filter(fn (CourtHearing $h) => $h->hearing_at->lt(now()->startOfDay()))->sortByDesc('hearing_at')->values();
    }

    public function isConvicted(): bool
    {
        return in_array($this->case_status, self::CONVICTED_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->case_status, self::CLOSED_STATUSES, true);
    }

    /** "6 years, 2 months" — or null without a sentence. */
    public function sentenceLabel(): ?string
    {
        $parts = collect(['year' => $this->sentence_years, 'month' => $this->sentence_months, 'day' => $this->sentence_days])
            ->filter(fn ($n) => $n > 0)
            ->map(fn ($n, $unit) => $n . ' ' . $unit . ($n === 1 ? '' : 's'));

        return $parts->isEmpty() ? null : $parts->join(', ');
    }

    /**
     * Rough release date: the PDL's admission (time in detention counts
     * toward the sentence) plus the sentence. Before good conduct time
     * allowance and other credits, so it is only an estimate.
     */
    public function estimatedRelease(): ?\Carbon\Carbon
    {
        $start = $this->pdl?->admission_date;
        if (! $this->isConvicted() || ! $start || ! $this->sentenceLabel()) {
            return null;
        }

        return $start->copy()
            ->addYears($this->sentence_years ?? 0)
            ->addMonths($this->sentence_months ?? 0)
            ->addDays($this->sentence_days ?? 0);
    }

    public function pdl()
    {
        return $this->belongsTo(Pdl::class, 'pdl_id', 'pdl_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(Account::class, 'recorded_by');
    }

    /** Drug-related case: a listed RA 9165 offense, or older free text that clearly is one. */
    public function isDrugRelated(): bool
    {
        return $this->offense_code
            ? \App\Support\Offenses::isDrug($this->offense_code)
            : \App\Support\Offenses::looksDrugRelated($this->offense);
    }

    /** Case numbers compared the way they are stored: uppercase, single spaces. */
    public static function normalizeCaseNumber(?string $caseNumber): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $caseNumber)));
    }

    /**
     * Co-accused lookup for the Cell/Block picker: every case number held by
     * a PDL in custody (active), with who holds it and their dorm.
     *
     * @return array<string, array<int, array{pdl_id: int, name: string, dorm: ?string}>>
     */
    public static function coAccusedMap(): array
    {
        return self::query()
            ->whereHas('pdl', fn ($q) => $q->where('custody_status', 'active'))
            ->with('pdl:pdl_id,full_name,cell_block')
            ->get(['pdl_id', 'case_number'])
            ->groupBy(fn (self $r) => self::normalizeCaseNumber($r->case_number))
            ->map(fn ($records) => $records->unique('pdl_id')->map(fn (self $r) => [
                'pdl_id' => (int) $r->pdl_id,
                'name' => $r->pdl->full_name,
                'dorm' => $r->pdl->cell_block,
            ])->values()->all())
            ->all();
    }
}
