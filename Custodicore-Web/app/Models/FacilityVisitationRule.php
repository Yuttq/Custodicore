<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A weekly visiting window: day + hours + visitor capacity per session.
 *
 * Each rule belongs to one "group":
 *   - a dorm (cell_block_id set) — that dorm's own visiting schedule, or
 *   - a PDL classification (cell_block_id null) — the original facility-wide
 *     policy: drug_related Thu/Sat, non_drug_related Fri/Sun (DatabaseSeeder).
 *
 * A PDL follows their dorm's rules when the dorm has any; otherwise the
 * classification rules still apply. rulesForPdl()/groupKeyForPdl() are the
 * single place that decides this — every scheduling path goes through them.
 */
class FacilityVisitationRule extends Model
{
    protected $table = 'facility_visitation_rules';
    protected $primaryKey = 'rule_id';
    public $timestamps = false;

    public const DAY_LABELS = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    protected $fillable = [
        'cell_block_id',
        'pdl_classification',
        'day_of_week',
        'time_slot_start',
        'time_slot_end',
        'max_capacity',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function schedules()
    {
        return $this->hasMany(VisitSchedule::class, 'rule_id', 'rule_id');
    }

    public function cellBlock()
    {
        return $this->belongsTo(CellBlock::class, 'cell_block_id', 'cell_block_id');
    }

    // --- Groups -------------------------------------------------------------

    /** "dorm:12" for a dorm rule, otherwise the classification. */
    public function groupKey(): string
    {
        return $this->cell_block_id ? 'dorm:' . $this->cell_block_id : (string) $this->pdl_classification;
    }

    /** Which rule group a PDL's visits follow: their dorm's, if it has rules; else their classification's. */
    public static function groupKeyForPdl(Pdl $pdl): string
    {
        // Per-request cache (container instance): dorm name => id for dorms with their own rules.
        if (! app()->bound(self::CACHE_KEY)) {
            // Only dorms with hours still in effect; a dorm whose hours were all
            // removed falls back to the classification days.
            app()->instance(self::CACHE_KEY, self::query()
                ->whereNotNull('facility_visitation_rules.cell_block_id')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
                ->join('cell_blocks', 'cell_blocks.cell_block_id', '=', 'facility_visitation_rules.cell_block_id')
                ->pluck('facility_visitation_rules.cell_block_id', 'cell_blocks.name')
                ->all());
        }
        $dormsWithRules = app(self::CACHE_KEY);

        $dormId = $pdl->cell_block !== null ? ($dormsWithRules[$pdl->cell_block] ?? null) : null;

        return $dormId ? 'dorm:' . $dormId : (string) $pdl->classification;
    }

    /** Forget the cached dorm lookup (after dorm rules are added or removed). */
    public static function flushGroupCache(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    private const CACHE_KEY = 'visitation.dorms_with_rules';

    protected static function booted(): void
    {
        static::saved(fn () => self::flushGroupCache());
        static::deleted(fn () => self::flushGroupCache());
    }

    public function scopeForGroup(Builder $query, string $groupKey): Builder
    {
        if (str_starts_with($groupKey, 'dorm:')) {
            return $query->where('cell_block_id', (int) substr($groupKey, 5));
        }

        return $query->whereNull('cell_block_id')->where('pdl_classification', $groupKey);
    }

    /** The rules that apply to $pdl, filtered from $all when the caller already loaded every rule. */
    public static function rulesForPdl(Pdl $pdl, ?Collection $all = null): Collection
    {
        $key = self::groupKeyForPdl($pdl);

        return $all !== null
            ? $all->filter(fn (self $rule) => $rule->groupKey() === $key)->values()
            : self::forGroup($key)->orderBy('time_slot_start')->get();
    }

    public function appliesTo(Pdl $pdl): bool
    {
        return $this->groupKey() === self::groupKeyForPdl($pdl);
    }

    /** "Dorm 3" or "Drug-related PDLs" — for messages and lists. */
    public static function groupLabel(string $groupKey): string
    {
        if (str_starts_with($groupKey, 'dorm:')) {
            return CellBlock::whereKey((int) substr($groupKey, 5))->value('name') ?? 'Dorm';
        }

        return match ($groupKey) {
            'drug_related' => 'Drug-related PDLs',
            'non_drug_related' => 'Non-drug-related PDLs',
            default => $groupKey,
        };
    }

    public function isInEffectOn(string $date): bool
    {
        return (! $this->effective_from || $date >= $this->effective_from->toDateString())
            && (! $this->effective_to || $date <= $this->effective_to->toDateString());
    }

    /** "08:00–11:30" */
    public function hoursLabel(): string
    {
        return substr((string) $this->time_slot_start, 0, 5) . '–' . substr((string) $this->time_slot_end, 0, 5);
    }
}
