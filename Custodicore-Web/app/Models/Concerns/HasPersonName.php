<?php

namespace App\Models\Concerns;

use App\Support\Sanitizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Name helpers shared by PDLs, staff and visitors (models with full_name plus
 * the nullable first_name / middle_name / last_name / suffix parts).
 *
 * - full_name stays in natural order ("Juan Santos Dela Cruz Jr."): duplicate
 *   checks, walk-in matching, audit text and the mobile API all read it.
 * - listName() is the staff-facing form ("Dela Cruz, Juan Santos Jr.").
 * - Older records with only full_name show it as is; nothing is guessed.
 */
trait HasPersonName
{
    /** "Juan Santos Dela Cruz Jr." — natural order, empty parts left out. */
    public static function composeFullName(?string $first, ?string $middle, ?string $last, ?string $suffix = null): string
    {
        return implode(' ', array_filter(
            array_map(fn ($part) => trim((string) $part), [$first, $middle, $last, $suffix]),
            fn ($part) => $part !== ''
        ));
    }

    /** "Dela Cruz, Juan Santos Jr." — Last, First Middle Suffix. */
    public function listName(): string
    {
        $first = trim((string) $this->first_name);
        $last = trim((string) $this->last_name);
        if ($first === '' && $last === '') {
            return (string) $this->full_name;
        }

        $given = trim(implode(' ', array_filter([$first, trim((string) $this->middle_name)])));
        $name = $last !== '' ? $last . ($given !== '' ? ', ' . $given : '') : $given;
        $suffix = trim((string) $this->suffix);

        return $suffix !== '' ? $name . ' ' . $suffix : $name;
    }

    /** Alphabetical by last name, then first and middle; older full-name-only rows sort by full_name. */
    public function scopeOrderByName(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->orderByRaw("COALESCE(NULLIF({$table}.last_name, ''), {$table}.full_name)")
            ->orderBy("{$table}.first_name")
            ->orderBy("{$table}.middle_name");
    }

    /**
     * Every word of the search must appear in full_name, in any order, so
     * "Juan Dela Cruz", "Dela Cruz, Juan" and "cruz juan" all find the same
     * person. Commas are ignored. Use inside a where(fn) group.
     */
    public function scopeWhereNameMatches(Builder $query, string $term): Builder
    {
        $column = $this->getTable() . '.full_name';
        $words = preg_split('/[\s,]+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
        if (! $words) {
            return $query->where($column, 'like', Sanitizer::like(trim($term)));
        }

        return $query->where(function (Builder $q) use ($column, $words) {
            foreach ($words as $word) {
                $q->where($column, 'like', Sanitizer::like($word));
            }
        });
    }
}
