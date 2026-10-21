<?php

namespace App\Services;

use App\Models\FacilityVisitationRule;
use App\Models\Pdl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Can this PDL be visited, and when? Based on custody status, active
 * restrictions and the PDL's visiting schedule (their dorm's hours, or the
 * classification days when the dorm has none — FacilityVisitationRule).
 *
 * Returns:
 *   state    open | today | next | none | restricted | not_in_custody
 *   label    short text for a badge ("Available now", "Next: Thu 08:00–11:30")
 *   source   "Dorm 3 schedule" | "Drug-related days"
 *   weekly   "Mon, Thu 08:00–11:30 · Sat 13:00–16:30"
 * Times are 24-hour, in the facility timezone.
 */
class PdlVisitingAvailability
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']; // Carbon dayOfWeek order

    /** @param Collection<int, FacilityVisitationRule>|null $allRules pass every rule when checking many PDLs */
    public static function for(Pdl $pdl, ?Collection $allRules = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(config('visitation.timezone', 'Asia/Manila'));
        $group = FacilityVisitationRule::groupKeyForPdl($pdl);
        $source = str_starts_with($group, 'dorm:')
            ? ($pdl->cell_block . ' schedule')
            : FacilityVisitationRule::groupLabel($group) . ' days';
        $rules = FacilityVisitationRule::rulesForPdl($pdl, $allRules)
            ->filter(fn ($r) => $r->isInEffectOn($now->toDateString()))
            ->values();

        $base = ['source' => $source, 'weekly' => self::weekly($rules)];

        if ($pdl->custody_status !== 'active') {
            return $base + ['state' => 'not_in_custody', 'label' => 'Not in custody (' . ucfirst($pdl->custody_status) . ')'];
        }

        $restriction = $pdl->relationLoaded('restrictions')
            ? $pdl->restrictions->first(fn ($r) => $r->isActive())
            : $pdl->activeRestrictions()->first();
        if ($restriction) {
            return $base + ['state' => 'restricted', 'label' => 'Restricted: ' . ucwords(str_replace('_', ' ', $restriction->restriction_type))];
        }

        if ($rules->isEmpty()) {
            return $base + ['state' => 'none', 'label' => 'No visiting hours set'];
        }

        // Today first, then the next 7 days.
        for ($i = 0; $i <= 7; $i++) {
            $day = $now->addDays($i);
            $windows = $rules->where('day_of_week', self::DAY_KEYS[$day->dayOfWeek])
                ->filter(fn ($r) => $r->isInEffectOn($day->toDateString()))
                ->sortBy(fn ($r) => (string) $r->time_slot_start);

            foreach ($windows as $rule) {
                $start = substr((string) $rule->time_slot_start, 0, 5);
                $end = substr((string) $rule->time_slot_end, 0, 5);

                if ($i === 0) {
                    $time = $now->format('H:i');
                    if ($time >= $start && $time < $end) {
                        return $base + ['state' => 'open', 'label' => "Available now · until {$end}"];
                    }
                    if ($time < $start) {
                        return $base + ['state' => 'today', 'label' => "Today {$start}–{$end}"];
                    }
                    continue; // already over today
                }

                $when = $i === 1 ? 'Tomorrow' : $day->format('D, M j');

                return $base + ['state' => 'next', 'label' => "Next: {$when} {$start}–{$end}"];
            }
        }

        return $base + ['state' => 'none', 'label' => 'No upcoming visiting hours'];
    }

    /** "Mon, Thu 08:00–11:30 · Sat 13:00–16:30" */
    public static function weekly(Collection $rules): string
    {
        $order = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        return $rules->groupBy(fn ($r) => $r->hoursLabel())
            ->sortKeys()
            ->map(fn ($group, $hours) => $group->sortBy(fn ($r) => array_search($r->day_of_week, $order, true))
                ->map(fn ($r) => ucfirst($r->day_of_week))->unique()->join(', ') . ' ' . $hours)
            ->join(' · ');
    }

    /** Badge class for the state. */
    public static function badgeClass(string $state): string
    {
        return match ($state) {
            'open' => 'active',
            'today', 'next' => 'detained',
            'restricted' => 'restricted',
            default => 'released',
        };
    }
}
