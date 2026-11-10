<?php

namespace App\Services;

use App\Models\CellBlock;
use App\Models\CourtHearing;
use App\Models\FacilityVisitationRule;
use App\Models\PdlLegalRecord;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Carbon\CarbonImmutable;

/**
 * Records Officer sidebar badges: real counts of what still needs doing,
 * so an officer sees which sections need attention without opening each one.
 *
 *   count (red)    work waiting for a decision
 *   warn  (orange) something to fix ("!"), e.g. setup that is missing
 *   info  (blue)   worth knowing, no action required
 *
 * A badge is null when there is nothing to show. The layout polls
 * GET /nav-badges, so they update without reloading or signing in again.
 * Clicking a badge opens its `url`: the items it counts, filtered.
 *
 * @phpstan-type Badge array{kind: string, value: int, title: string}
 */
class NavBadges
{
    /** How far ahead "upcoming" hearings and unpublished sessions look. */
    private const DAYS_AHEAD = 7;

    /** @return array<string, ?array{kind: string, value: int, title: string}> keyed by sidebar item */
    public function all(): array
    {
        $visits = VisitRequest::awaitingReview()->count();
        $review = array_sum(ReviewQueue::counts());

        return [
            'pdls' => $this->badge('info', self::hearingsAheadQuery()->count(), fn ($n) => "{$n} court " . ($n === 1 ? 'hearing' : 'hearings') . ' in the next ' . self::DAYS_AHEAD . ' days',
                route('pdl.index', ['hearing' => 'week'])),
            'cells' => $this->badge('warn', $this->dormsOver(), fn ($n) => "{$n} " . ($n === 1 ? 'dorm is' : 'dorms are') . ' over the recommended capacity',
                route('cell-blocks.index', ['over' => 1])),
            'visitors' => $this->badge('count', $visits, fn ($n) => "{$n} visit " . ($n === 1 ? 'request' : 'requests') . ' awaiting review',
                route('visitor.index') . '#visit-requests'),
            'review' => $this->badge('count', $review, fn ($n) => "{$n} waiting for review", route('review-queue')),
            'upcoming' => $this->upcomingBadge(),
            'slots' => $this->badge('warn', $this->unpublishedSessions()->count(), fn ($n) => "{$n} visiting " . ($n === 1 ? 'session' : 'sessions') . ' in the next ' . self::DAYS_AHEAD . ' days ' . ($n === 1 ? 'is' : 'are') . ' not published yet, so visitors cannot book ' . ($n === 1 ? 'it' : 'them'),
                route('visitor-slots.index') . '#unpublished'),
        ];
    }

    private function badge(string $kind, int $value, callable $title, ?string $url = null): ?array
    {
        return $value > 0 ? ['kind' => $kind, 'value' => $value, 'title' => $title($value), 'url' => $url] : null;
    }

    /**
     * Hearings from now through the next week, for PDLs in custody on open
     * cases. Shared with the PDL list's "hearing this week" filter and tag.
     */
    public static function hearingsAheadQuery()
    {
        return CourtHearing::query()
            ->whereBetween('hearing_at', [now(), now()->addDays(self::DAYS_AHEAD)->endOfDay()])
            ->whereHas('legalRecord', fn ($q) => $q->whereNotIn('case_status', PdlLegalRecord::CLOSED_STATUSES)
                ->whereHas('pdl', fn ($p) => $p->where('custody_status', 'active')));
    }

    private function dormsOver(): int
    {
        return CellBlock::withOccupancy()->where('is_active', true)->get()->filter->isOver()->count();
    }

    /**
     * Who is coming today: confirmed visits. Records Officers no longer
     * assign visits, so staff-assigned visits waiting for the visitor to
     * confirm are not counted (they still show on the Upcoming Visits page).
     */
    private function upcomingBadge(): ?array
    {
        return $this->badge('info', $this->visitsToday(),
            fn ($n) => "{$n} confirmed " . ($n === 1 ? 'visit' : 'visits') . ' today',
            route('upcoming-visits.index', ['range' => 'today']));
    }

    private function visitsToday(): int
    {
        return VisitRequest::query()
            ->where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', now()->toDateString()))
            ->count();
    }

    /**
     * Weekly sessions (Thursday to Sunday) in the next week that have no
     * published session yet. Visitors can only book published sessions.
     * Sessions that already ended today don't count.
     */
    /**
     * @return \Illuminate\Support\Collection<int, array{rule: FacilityVisitationRule, date: CarbonImmutable}>
     */
    public function unpublishedSessions(): \Illuminate\Support\Collection
    {
        $availability = app(ScheduleAvailabilityService::class);
        $today = $availability->now()->startOfDay();
        $keys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        $rules = FacilityVisitationRule::query()
            ->whereIn('day_of_week', VisitSlotService::VISITING_DAYS)
            ->get();
        $published = VisitSchedule::query()
            ->whereBetween('schedule_date', [$today->toDateString(), $today->addDays(self::DAYS_AHEAD - 1)->toDateString()])
            ->get(['rule_id', 'schedule_date'])
            ->map(fn ($s) => $s->rule_id . '|' . $s->schedule_date->toDateString())
            ->flip();

        $missing = collect();
        for ($i = 0; $i < self::DAYS_AHEAD; $i++) {
            /** @var CarbonImmutable $date */
            $date = $today->addDays($i);
            $day = $date->toDateString();
            foreach ($rules->where('day_of_week', $keys[$date->dayOfWeek]) as $rule) {
                if (! $rule->isInEffectOn($day)
                    || $published->has($rule->rule_id . '|' . $day)
                    || $availability->sessionHasEnded($day, $rule->time_slot_end)) {
                    continue;
                }
                $missing->push(['rule' => $rule, 'date' => $date]);
            }
        }

        return $missing;
    }
}
