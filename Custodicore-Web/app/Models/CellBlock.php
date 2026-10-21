<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// A cell block / dorm the Record Officer can assign PDLs to. Linked to
// pdl_profiles by name (pdl_profiles.cell_block), not by id — see the
// create_cell_blocks_table migration for why.
class CellBlock extends Model
{
    protected $table = 'cell_blocks';
    protected $primaryKey = 'cell_block_id';

    public const DESIGNATIONS = ['any', 'male', 'female'];

    // Only PDLs physically in custody count toward a dorm.
    public const OCCUPYING_STATUS = 'active';

    protected $fillable = [
        'name',
        'capacity',
        'designation',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // A renamed dorm changes which PDLs follow its visiting schedule.
        static::saved(fn () => FacilityVisitationRule::flushGroupCache());
    }

    public function visitationRules()
    {
        return $this->hasMany(FacilityVisitationRule::class, 'cell_block_id', 'cell_block_id');
    }

    /**
     * "Mon, Thu 08:00–11:30 · Sat 13:00–16:30", or null when the dorm has no
     * visiting hours of its own (its PDLs then follow the classification days).
     */
    public function visitingSummary(): ?string
    {
        $today = now()->toDateString();
        $order = array_keys(FacilityVisitationRule::DAY_LABELS);
        $rules = $this->visitationRules->filter(fn (FacilityVisitationRule $r) => $r->isInEffectOn($today) || ($r->effective_from && $r->effective_from->toDateString() > $today));
        if ($rules->isEmpty()) {
            return null;
        }

        return $rules->groupBy(fn ($r) => $r->hoursLabel())
            ->sortBy(fn ($group, $hours) => $hours)
            ->map(fn ($group, $hours) => $group->sortBy(fn ($r) => array_search($r->day_of_week, $order, true))
                ->map(fn ($r) => ucfirst($r->day_of_week))->unique()->join(', ') . ' ' . $hours)
            ->join(' · ');
    }

    public function pdls()
    {
        return $this->hasMany(Pdl::class, 'cell_block', 'name');
    }

    /** Adds `occupied_count` (active PDLs in this block) to each row. */
    public function scopeWithOccupancy(Builder $query): Builder
    {
        return $query->withCount([
            'pdls as occupied_count' => fn ($q) => $q->where('custody_status', self::OCCUPYING_STATUS),
        ]);
    }

    public function occupied(): int
    {
        return (int) ($this->occupied_count ?? $this->pdls()->where('custody_status', self::OCCUPYING_STATUS)->count());
    }

    public function available(): int
    {
        return max(0, $this->capacity - $this->occupied());
    }

    /**
     * Capacity is the RECOMMENDED number of occupants, not a hard limit:
     * dorms are overpopulated in practice, so PDLs can still be assigned
     * beyond it. isFull()/isOver() only drive the yellow warning.
     */
    public function isFull(): bool
    {
        return $this->available() === 0;
    }

    public function isOver(): bool
    {
        return $this->occupied() > $this->capacity;
    }

    public function overBy(): int
    {
        return max(0, $this->occupied() - $this->capacity);
    }

    /** "3 available" / "At capacity" / "Over by 4". */
    public function occupancyLabel(): string
    {
        return match (true) {
            $this->isOver() => 'Over by ' . $this->overBy(),
            $this->isFull() => 'At capacity',
            default => $this->available() . ' available',
        };
    }

    public function acceptsGender(?string $gender): bool
    {
        return $this->designation === 'any' || $this->designation === $gender;
    }

    public function designationLabel(): string
    {
        return match ($this->designation) {
            'male' => 'Male only',
            'female' => 'Female only',
            default => 'Any',
        };
    }
}
