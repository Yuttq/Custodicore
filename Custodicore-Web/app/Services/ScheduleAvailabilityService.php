<?php

namespace App\Services;

use App\Models\FacilityVisitationRule;
use App\Models\SystemSetting;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use App\Models\VisitSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only visit-slot availability for the mobile visitor calendar.
 *
 * Availability is calculated from the facility_visitation_rules policy for
 * every date in the range — visit_schedules rows are only seeded for a few
 * dates, so they are used as an overlay (capacity taken, full, closed) when
 * one exists for that date + slot, never as the list of possible slots.
 * Nothing is written: viewing availability creates no visit_schedules rows
 * (VisitAssignmentService::submitVisitorRequest creates one on demand).
 *
 * Slots are the sessions of a visiting day (morning, afternoon). A visit is
 * one day: the visitor picks one or more of that day's available sessions
 * and they form ONE visit (one visit.max_per_week count). Each day also
 * reports `wholeDay` — all of its sessions as one visit — when it has more
 * than one session.
 *
 * Unavailable slots carry a machine-readable `reason`:
 *   past             — the date is before today (Asia/Manila)
 *   ended            — today, but the slot's end time has passed
 *   not_eligible     — the PDL's classification has no visiting rule that day
 *   not_in_effect    — a rule exists for that day but is outside effective_from/to
 *   pdl_unavailable  — PDL not in active custody, or has an active restriction
 *   closed           — the overlaid visit_schedules row is closed
 *   already_scheduled — this visitor already has an active visit for this PDL on this date
 *   full             — no capacity left
 *   weekly_limit     — the visitor already holds visit.max_per_week visits that week
 */
class ScheduleAvailabilityService
{
    public const REASON_PAST = 'past';
    public const REASON_ENDED = 'ended';
    public const REASON_NOT_ELIGIBLE = 'not_eligible';
    public const REASON_NOT_IN_EFFECT = 'not_in_effect';
    public const REASON_PDL_UNAVAILABLE = 'pdl_unavailable';
    public const REASON_CLOSED = 'closed';
    public const REASON_ALREADY_SCHEDULED = 'already_scheduled';
    public const REASON_FULL = 'full';
    public const REASON_WEEKLY_LIMIT = 'weekly_limit';

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function timezone(): string
    {
        return config('visitation.timezone', 'Asia/Manila');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * The visitor's verified relationships, optionally narrowed to one.
     *
     * @return Collection<int, VisitorPdlRelationship>
     */
    public function verifiedRelationships(VisitorProfile $visitor): Collection
    {
        return VisitorPdlRelationship::where('visitor_id', $visitor->visitor_id)
            ->where('verification_status', 'verified')
            ->with(['pdl.activeRestrictions'])
            ->orderBy('relationship_id')
            ->get()
            ->filter(fn ($r) => $r->pdl !== null)
            ->values();
    }

    /**
     * Availability for each relationship over [from, to] (inclusive, Y-m-d).
     *
     * @param  Collection<int, VisitorPdlRelationship>  $relationships
     * @return array<int, array<string, mixed>>
     */
    public function forRelationships(
        VisitorProfile $visitor,
        Collection $relationships,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): array {
        if ($relationships->isEmpty()) {
            return [];
        }

        $now = $this->now();
        $rules = FacilityVisitationRule::orderBy('time_slot_start')->get();
        $schedules = $this->schedulesInRange($from, $to);
        $occupied = $this->occupiedBySchedule($schedules);
        $ownBookings = $this->visitorBookings($visitor, $from, $to);
        $weekly = [
            'counts' => $this->weeklyVisitCounts($visitor, $from, $to),
            'limit' => $this->maxVisitsPerWeek(),
        ];

        return $relationships->map(fn (VisitorPdlRelationship $relationship) => $this->relationshipAvailability(
            $relationship, $rules, $schedules, $occupied, $ownBookings, $weekly, $from, $to, $now
        ))->values()->all();
    }

    /** Zero or negative allows no visits; a missing or non-integer value falls back to 2. */
    public function maxVisitsPerWeek(): int
    {
        $raw = trim((string) SystemSetting::value('visit.max_per_week', '2'));

        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            return 2;
        }

        return max((int) $raw, 0);
    }

    /**
     * `visit.max_per_week` counts: the visitor's visits in capacity statuses
     * (across all PDLs) per Monday–Sunday week of visit_schedules.schedule_date,
     * over the complete weeks covering [from, to]. A visit is one
     * visit_requests row however many sessions it has, so a whole-day visit
     * counts once.
     *
     * @return array<string, int> weekStart (Y-m-d Monday) => visits that week
     */
    public function weeklyVisitCounts(VisitorProfile $visitor, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weekStart = $this->weekStart($from);
        $weekEnd = CarbonImmutable::parse($this->weekStart($to))->addDays(6)->toDateString();

        $counts = [];
        VisitRequest::query()
            ->join('visit_schedules', 'visit_schedules.schedule_id', '=', 'visit_requests.schedule_id')
            ->where('visit_requests.visitor_id', $visitor->visitor_id)
            ->whereIn('visit_requests.status', VisitAssignmentService::CAPACITY_STATUSES)
            ->whereDate('visit_schedules.schedule_date', '>=', $weekStart)
            ->whereDate('visit_schedules.schedule_date', '<=', $weekEnd)
            ->selectRaw('visit_schedules.schedule_date as schedule_date, COUNT(*) as aggregate')
            ->groupBy('visit_schedules.schedule_date')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$counts) {
                $week = $this->weekStart(CarbonImmutable::parse(substr((string) $row->schedule_date, 0, 10)));
                $counts[$week] = ($counts[$week] ?? 0) + (int) $row->aggregate;
            });

        return $counts;
    }

    /** The Monday (Y-m-d) of $date's Monday–Sunday week. */
    public function weekStart(CarbonImmutable $date): string
    {
        return $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /**
     * The one slot starting at $startTime ("HH:MM") on $date for this
     * relationship, with the same availability/reason the calendar shows,
     * or null when the facility has no slot at that time that day.
     *
     * @return array<string, mixed>|null
     */
    public function slotFor(
        VisitorProfile $visitor,
        VisitorPdlRelationship $relationship,
        CarbonImmutable $date,
        string $startTime
    ): ?array {
        $relationship->loadMissing('pdl.activeRestrictions');
        if (! $relationship->pdl) {
            return null;
        }

        $result = $this->forRelationships($visitor, collect([$relationship]), $date, $date)[0];

        return collect($result['days'][0]['slots'] ?? [])->firstWhere('startTime', $this->hm($startTime));
    }

    private function relationshipAvailability(
        VisitorPdlRelationship $relationship,
        Collection $rules,
        Collection $schedules,
        array $occupied,
        array $ownBookings,
        array $weekly,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $now
    ): array {
        $pdl = $relationship->pdl;
        $classification = $pdl->classification;
        $pdlAvailable = $pdl->custody_status === 'active' && $pdl->activeRestrictions->isEmpty();
        $classificationRules = $rules->where('pdl_classification', $classification);

        $days = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $days[] = $this->dayAvailability(
                $date, $relationship, $classificationRules, $rules, $schedules,
                $occupied, $ownBookings, $weekly, $pdlAvailable, $now
            );
        }

        $today = $now->startOfDay();
        $visitingDays = $classificationRules
            ->filter(fn ($rule) => $this->ruleInEffect($rule, $today))
            ->pluck('day_of_week')
            ->unique()
            // Monday-first, so the app reads "Friday and Sunday".
            ->sortBy(fn ($day) => (array_search($day, self::DAY_KEYS, true) + 6) % 7)
            ->values()
            ->all();

        return [
            'relationshipId' => (string) $relationship->relationship_id,
            'pdlId' => (string) $pdl->pdl_id,
            'pdlName' => $pdl->full_name,
            'relationshipLabel' => $relationship->relationshipLabel(),
            'classification' => $classification,
            'classificationLabel' => $classification === 'drug_related' ? 'Drug-related' : 'Non-drug-related',
            'visitingDays' => $visitingDays,
            'pdlAvailable' => $pdlAvailable,
            'message' => $pdlAvailable
                ? null
                : 'Visits with this PDL are not available at this time. Please contact the facility for details.',
            'days' => $days,
        ];
    }

    private function dayAvailability(
        CarbonImmutable $date,
        VisitorPdlRelationship $relationship,
        Collection $classificationRules,
        Collection $allRules,
        Collection $schedules,
        array $occupied,
        array $ownBookings,
        array $weekly,
        bool $pdlAvailable,
        CarbonImmutable $now
    ): array {
        $dateString = $date->toDateString();
        $dayKey = self::DAY_KEYS[$date->dayOfWeek];
        $dayRules = $classificationRules->where('day_of_week', $dayKey);
        $effectiveRules = $dayRules->filter(fn ($rule) => $this->ruleInEffect($rule, $date));

        $slots = [];

        if ($effectiveRules->isNotEmpty()) {
            foreach ($effectiveRules as $rule) {
                $slots[] = $this->ruleSlot(
                    $date, $rule, $relationship, $schedules, $occupied, $ownBookings, $weekly, $pdlAvailable, $now
                );
            }
        } else {
            // No visiting for this PDL today: still describe the facility's
            // usual windows (from any rule in effect) so the app can show
            // "Not available for your schedule" rather than nothing.
            $reason = $dayRules->isNotEmpty() ? self::REASON_NOT_IN_EFFECT : self::REASON_NOT_ELIGIBLE;
            $windows = $allRules
                ->filter(fn ($rule) => $this->ruleInEffect($rule, $date))
                ->unique(fn ($rule) => $this->hm($rule->time_slot_start).'|'.$this->hm($rule->time_slot_end));

            foreach ($windows as $rule) {
                $slots[] = $this->slotPayload($date, $rule, $relationship, [
                    'available' => false,
                    'reason' => $this->timeReason($date, $rule, $now) ?? $reason,
                    'capacity' => 0,
                    'slotsRemaining' => 0,
                    'ruleId' => null,
                    'scheduleId' => null,
                ]);
            }
        }

        usort($slots, fn ($a, $b) => strcmp($a['startTime'], $b['startTime']));

        return [
            'date' => $dateString,
            'dayOfWeek' => $dayKey,
            'available' => collect($slots)->contains('available', true),
            'wholeDay' => $effectiveRules->count() > 1 ? $this->wholeDay($slots) : null,
            'slots' => $slots,
        ];
    }

    /**
     * All of the day's sessions booked as one visit (POST /api/visit-requests
     * with every startTime). Available only when every session is.
     *
     * @param  array<int, array<string, mixed>>  $slots  the day's slots, sorted by start
     */
    private function wholeDay(array $slots): array
    {
        $blocking = collect($slots)->firstWhere('available', false);

        return [
            'available' => $blocking === null,
            'reason' => $blocking['reason'] ?? null,
            'startTime' => $slots[0]['startTime'],
            'endTime' => $slots[count($slots) - 1]['endTime'],
            'startTimes' => array_column($slots, 'startTime'),
        ];
    }

    private function ruleSlot(
        CarbonImmutable $date,
        FacilityVisitationRule $rule,
        VisitorPdlRelationship $relationship,
        Collection $schedules,
        array $occupied,
        array $ownBookings,
        array $weekly,
        bool $pdlAvailable,
        CarbonImmutable $now
    ): array {
        $schedule = $schedules->get($this->scheduleKey($date->toDateString(), $rule->time_slot_start, $rule->rule_id));

        $capacity = (int) ($schedule?->max_capacity ?? $rule->max_capacity);
        $taken = $schedule
            ? max((int) $schedule->slots_taken, $occupied[$schedule->schedule_id] ?? 0)
            : 0;
        $remaining = max($capacity - $taken, 0);

        $reason = $this->timeReason($date, $rule, $now);
        if (! $reason && ! $pdlAvailable) {
            $reason = self::REASON_PDL_UNAVAILABLE;
        }
        if (! $reason && $schedule?->status === 'closed') {
            $reason = self::REASON_CLOSED;
        }
        if (! $reason && isset($ownBookings[$relationship->pdl_id.'|'.$date->toDateString()])) {
            $reason = self::REASON_ALREADY_SCHEDULED;
        }
        if (! $reason && ($schedule?->status === 'full' || $remaining <= 0)) {
            $reason = self::REASON_FULL;
        }
        if (! $reason && ($weekly['counts'][$this->weekStart($date)] ?? 0) >= $weekly['limit']) {
            $reason = self::REASON_WEEKLY_LIMIT;
        }

        return $this->slotPayload($date, $rule, $relationship, [
            'available' => $reason === null,
            'reason' => $reason,
            'capacity' => $capacity,
            'slotsRemaining' => $reason === self::REASON_CLOSED ? 0 : $remaining,
            'ruleId' => (string) $rule->rule_id,
            'scheduleId' => $schedule ? (string) $schedule->schedule_id : null,
        ]);
    }

    private function slotPayload(
        CarbonImmutable $date,
        FacilityVisitationRule $rule,
        VisitorPdlRelationship $relationship,
        array $state
    ): array {
        $start = $this->hm($rule->time_slot_start);
        $end = $this->hm($rule->time_slot_end);

        return [
            'slotKey' => $date->toDateString().'|'.$start,
            'date' => $date->toDateString(),
            'period' => (int) substr($start, 0, 2) < 12 ? 'morning' : 'afternoon',
            'startTime' => $start,
            'endTime' => $end,
            'available' => $state['available'],
            'reason' => $state['reason'],
            'capacity' => $state['capacity'],
            'slotsRemaining' => $state['slotsRemaining'],
            'relationshipId' => (string) $relationship->relationship_id,
            'ruleId' => $state['ruleId'],
            'scheduleId' => $state['scheduleId'],
        ];
    }

    /** `past` for an earlier date, `ended` once today's slot end time has passed. */
    private function timeReason(CarbonImmutable $date, FacilityVisitationRule $rule, CarbonImmutable $now): ?string
    {
        $today = $now->toDateString();
        $dateString = $date->toDateString();

        if ($dateString < $today) {
            return self::REASON_PAST;
        }

        if ($dateString === $today) {
            $end = CarbonImmutable::parse($dateString.' '.$this->hm($rule->time_slot_end), $this->timezone());
            if ($now->gte($end)) {
                return self::REASON_ENDED;
            }
        }

        return null;
    }

    private function ruleInEffect(FacilityVisitationRule $rule, CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        if ($rule->effective_from && $day < $rule->effective_from->toDateString()) {
            return false;
        }

        if ($rule->effective_to && $day > $rule->effective_to->toDateString()) {
            return false;
        }

        return true;
    }

    /** @return Collection<string, VisitSchedule> keyed by date|HH:MM|rule_id */
    private function schedulesInRange(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return VisitSchedule::whereDate('schedule_date', '>=', $from->toDateString())
            ->whereDate('schedule_date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (VisitSchedule $s) => $this->scheduleKey(
                $s->schedule_date->toDateString(), $s->time_slot_start, $s->rule_id
            ));
    }

    /** @return array<int, int> schedule_id => visit sessions that still hold a seat */
    private function occupiedBySchedule(Collection $schedules): array
    {
        if ($schedules->isEmpty()) {
            return [];
        }

        return VisitSession::query()
            ->join('visit_requests', 'visit_requests.visit_request_id', '=', 'visit_sessions.visit_request_id')
            ->whereIn('visit_sessions.schedule_id', $schedules->pluck('schedule_id'))
            ->whereIn('visit_requests.status', VisitAssignmentService::CAPACITY_STATUSES)
            ->selectRaw('visit_sessions.schedule_id as schedule_id, COUNT(*) as aggregate')
            ->groupBy('visit_sessions.schedule_id')
            ->toBase()
            ->pluck('aggregate', 'schedule_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * A visitor holds at most one active visit per PDL per day; its sessions
     * are chosen when it is requested.
     *
     * @return array<string, true> "pdl_id|Y-m-d" days on which this visitor already has a visit with that PDL
     */
    private function visitorBookings(VisitorProfile $visitor, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return VisitRequest::query()
            ->join('visit_schedules', 'visit_schedules.schedule_id', '=', 'visit_requests.schedule_id')
            ->where('visit_requests.visitor_id', $visitor->visitor_id)
            ->whereIn('visit_requests.status', VisitAssignmentService::CAPACITY_STATUSES)
            ->whereDate('visit_schedules.schedule_date', '>=', $from->toDateString())
            ->whereDate('visit_schedules.schedule_date', '<=', $to->toDateString())
            ->toBase()
            ->get(['visit_requests.pdl_id', 'visit_schedules.schedule_date'])
            ->mapWithKeys(fn ($v) => [$v->pdl_id.'|'.substr((string) $v->schedule_date, 0, 10) => true])
            ->all();
    }

    private function scheduleKey(string $date, $start, $ruleId): string
    {
        return $date.'|'.$this->hm($start).'|'.$ruleId;
    }

    /** "09:00:00" -> "09:00" */
    private function hm($time): string
    {
        return substr((string) $time, 0, 5);
    }
}
