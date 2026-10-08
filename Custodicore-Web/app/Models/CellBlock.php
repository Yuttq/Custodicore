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

    // Only PDLs physically in custody take up a bed.
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

    public function isFull(): bool
    {
        return $this->available() === 0;
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
