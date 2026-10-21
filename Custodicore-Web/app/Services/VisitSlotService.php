<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FacilityVisitationRule;
use App\Models\Module;
use App\Models\VisitSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manage Visitor Slots (Record Officer): the capacity of each dated visiting
 * session (visit_schedules row) and the default capacity of each weekly
 * session (facility_visitation_rules row).
 *
 * Which days and times visiting happens stays defined by the weekly rules;
 * a dated session is always one rule's session on one of its days. The
 * capacity concepts are kept apart:
 *
 *   capacity  — visit_schedules.max_capacity, set here
 *   booked    — visit sessions whose visit is in
 *               VisitAssignmentService::CAPACITY_STATUSES (cancelled and
 *               declined visits do not hold a seat)
 *   remaining — capacity minus seats held; 0 while the session is closed
 *
 * visit.max_per_week (one visitor's visits per week) is a separate limit and
 * is not read or changed here.
 *
 * Every change to a dated session runs under the same visit_schedules row
 * lock the booking paths take (VisitAssignmentService), so a capacity
 * change and a booking can never both act on a stale count. The status is
 * derived on every save: `closed` when staff closed it, otherwise `full`
 * once booked reaches capacity, otherwise `open`. slots_taken is a cached
 * counter of booked seats and is reset to the real count on each save.
 */
class VisitSlotService
{
    public const MIN_CAPACITY = 1;
    public const MAX_CAPACITY = 1000;

    public const SESSIONS = ['morning', 'afternoon'];

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct(
        private ScheduleAvailabilityService $availability,
        private VisitAssignmentService $assignments
    ) {
    }

    /** "morning" for a session starting before noon, else "afternoon" (same split as the visitor calendar). */
    public static function sessionOf($startTime): string
    {
        return (int) substr((string) $startTime, 0, 2) < 12 ? 'morning' : 'afternoon';
    }

    /**
     * Capacity figures for each schedule.
     *
     * `held` is what booking enforces: the larger of the real bookings and
     * the slots_taken counter (seeded rows can carry a counter with no
     * bookings behind it). `remaining` is what visitors are offered.
     *
     * @param  Collection<int, VisitSchedule>  $schedules
     * @return array<int, array{capacity:int, booked:int, held:int, remaining:int, accepting:bool}> keyed by schedule_id
     */
    public function summaries(Collection $schedules): array
    {
        $occupied = $this->availability->occupiedBySchedule($schedules);

        return $schedules->mapWithKeys(function (VisitSchedule $schedule) use ($occupied) {
            $capacity = (int) $schedule->max_capacity;
            $booked = $occupied[$schedule->schedule_id] ?? 0;
            $held = max($booked, (int) $schedule->slots_taken);
            $remaining = $schedule->status === 'closed' ? 0 : max($capacity - $held, 0);

            return [$schedule->schedule_id => [
                'capacity' => $capacity,
                'booked' => $booked,
                'held' => $held,
                'remaining' => $remaining,
                'accepting' => $schedule->status === 'open' && $remaining > 0,
            ]];
        })->all();
    }

    /**
     * Whether staff may still change this session: until its end time
     * (facility timezone). Capacity and open/closed only govern new
     * bookings, which the visitor calendar already refuses once a session
     * has ended (`ended`); check-in/check-out never read them.
     */
    public function isEditable(VisitSchedule $schedule): bool
    {
        return ! $this->availability->sessionHasEnded($schedule->schedule_date->toDateString(), $schedule->time_slot_end);
    }

    /** The weekly rule that defines $session on $date for PDLs of $classification, if any is in effect. */
    public function ruleFor(string $classification, CarbonImmutable $date, string $session): ?FacilityVisitationRule
    {
        $day = $date->toDateString();

        // $classification is a rule group: a classification or "dorm:{id}".
        return FacilityVisitationRule::forGroup($classification)
            ->where('day_of_week', self::DAY_KEYS[$date->dayOfWeek])
            ->orderBy('time_slot_start')
            ->get()
            ->first(fn (FacilityVisitationRule $rule) => self::sessionOf($rule->time_slot_start) === $session
                && (! $rule->effective_from || $day >= $rule->effective_from->toDateString())
                && (! $rule->effective_to || $day <= $rule->effective_to->toDateString()));
    }

    /**
     * Creates the dated session for $classification's $session on $date with
     * $capacity seats. Times come from the weekly rule. Refused when the date
     * is past or too far ahead, when no weekly rule defines that session,
     * or when the session already exists (edit it instead).
     */
    public function create(string $classification, CarbonImmutable $date, string $session, int $capacity, bool $open): VisitSchedule
    {
        $this->assertCapacityInRange($capacity);

        $today = $this->availability->now()->startOfDay();
        if ($date->lt($today)) {
            throw ValidationException::withMessages([
                'schedule_date' => 'The visiting date cannot be in the past.',
            ]);
        }

        $horizon = (int) config('visitation.slot_management_horizon_days', 365);
        if ($date->gt($today->addDays($horizon))) {
            throw ValidationException::withMessages([
                'schedule_date' => "The visiting date must be within {$horizon} days from today.",
            ]);
        }

        $rule = $this->ruleFor($classification, $date, $session);
        if (! $rule) {
            throw ValidationException::withMessages([
                'session' => $this->noRuleMessage($classification, $date, $session),
            ]);
        }

        if ($this->availability->sessionHasEnded($date->toDateString(), $rule->time_slot_end)) {
            throw ValidationException::withMessages([
                'session' => "Today's {$session} session has already ended.",
            ]);
        }

        $duplicate = ['schedule_date' => "The {$session} session on {$date->format('M j, Y')} already exists. Edit it instead."];

        if ($this->findRow($rule, $date)) {
            throw ValidationException::withMessages($duplicate);
        }

        try {
            $schedule = DB::transaction(function () use ($rule, $date, $capacity, $open) {
                $schedule = VisitSchedule::create([
                    'rule_id' => $rule->rule_id,
                    'schedule_date' => $date->toDateString(),
                    'time_slot_start' => $rule->time_slot_start,
                    'time_slot_end' => $rule->time_slot_end,
                    'max_capacity' => $capacity,
                    'slots_taken' => 0,
                    'status' => $open ? 'open' : 'closed',
                ]);

                AuditLog::record(
                    'create',
                    'visit_schedules',
                    $schedule->schedule_id,
                    "Created visiting session {$this->label($schedule)} with capacity {$capacity}"
                        .($open ? '' : ' (closed to new bookings)'),
                    Module::CODE_VISIT_SCHEDULING
                );

                return $schedule;
            });
        } catch (UniqueConstraintViolationException) {
            // A visitor's booking created the row on demand in the meantime.
            throw ValidationException::withMessages($duplicate);
        }

        return $schedule->fresh('rule');
    }

    /**
     * Sets a dated session's capacity and whether it takes new bookings.
     * Capacity may not go below the seats booked right now (read under the
     * row lock). Existing bookings are never cancelled by this: closing only
     * stops new ones.
     *
     * A null $capacity keeps the session's current capacity, read under the
     * row lock — never a value read earlier — so an open/close cannot undo
     * a capacity change another officer saved in the meantime.
     */
    public function update(VisitSchedule $schedule, ?int $capacity, bool $open): VisitSchedule
    {
        if ($capacity !== null) {
            $this->assertCapacityInRange($capacity);
        }

        return DB::transaction(function () use ($schedule, $capacity, $open) {
            $locked = VisitSchedule::whereKey($schedule->schedule_id)->lockForUpdate()->firstOrFail();

            if (! $this->isEditable($locked)) {
                throw ValidationException::withMessages([
                    'schedule_date' => 'This session has already ended and can no longer be changed.',
                ]);
            }

            $booked = $this->assignments->occupiedSlots($locked);
            $keepCapacity = $capacity === null;
            $capacity ??= (int) $locked->max_capacity;

            // The floor applies to a capacity being set. Keeping the current
            // one never blocks closing or reopening; a reopened session with
            // every seat taken is derived as `full` below.
            if (! $keepCapacity && $capacity < $booked) {
                throw ValidationException::withMessages([
                    'max_capacity' => "This session has {$booked} active ".($booked === 1 ? 'booking' : 'bookings')
                        .", so its capacity cannot be lower than {$booked}.",
                ]);
            }

            $before = ['capacity' => (int) $locked->max_capacity, 'status' => $locked->status];
            $status = ! $open ? 'closed' : ($booked >= $capacity ? 'full' : 'open');

            $locked->update([
                'max_capacity' => $capacity,
                'slots_taken' => $booked,
                'status' => $status,
            ]);

            $changes = [];
            if ($before['capacity'] !== $capacity) {
                $changes[] = "capacity {$before['capacity']} → {$capacity}";
            }
            if ($before['status'] !== $status) {
                $changes[] = "status {$before['status']} → {$status}";
            }

            AuditLog::record(
                'update',
                'visit_schedules',
                $locked->schedule_id,
                "Updated visiting session {$this->label($locked)}: "
                    .($changes ? implode(', ', $changes) : 'no change')." ({$booked} booked)",
                Module::CODE_VISIT_SCHEDULING
            );

            return $locked->fresh('rule');
        });
    }

    /** Opens or closes a session to new bookings, keeping its latest capacity. */
    public function setOpen(VisitSchedule $schedule, bool $open): VisitSchedule
    {
        return $this->update($schedule, null, $open);
    }

    /**
     * Default capacity of a weekly session: used for every date of that
     * session that has no dated session row yet (the visitor calendar shows
     * it, and a booking creates the row with it). Rows that already exist
     * keep their own capacity.
     */
    public function updateRuleCapacity(FacilityVisitationRule $rule, int $capacity): FacilityVisitationRule
    {
        $this->assertCapacityInRange($capacity);

        return DB::transaction(function () use ($rule, $capacity) {
            $locked = FacilityVisitationRule::whereKey($rule->rule_id)->lockForUpdate()->firstOrFail();
            $before = (int) $locked->max_capacity;
            $locked->update(['max_capacity' => $capacity]);

            AuditLog::record(
                'update',
                'facility_visitation_rules',
                $locked->rule_id,
                'Updated default capacity of the '.self::ruleLabel($locked)." session: {$before} → {$capacity}",
                Module::CODE_VISIT_SCHEDULING
            );

            return $locked;
        });
    }

    /** "Fri morning (09:00–11:30), non-drug-related" */
    public static function ruleLabel(FacilityVisitationRule $rule): string
    {
        $group = $rule->pdl_classification === 'drug_related' ? 'drug-related' : 'non-drug-related';

        return ucfirst($rule->day_of_week).' '.self::sessionOf($rule->time_slot_start)
            .' ('.substr((string) $rule->time_slot_start, 0, 5).'–'.substr((string) $rule->time_slot_end, 0, 5)."), {$group}";
    }

    /**
     * Why ruleFor() found no rule, worded from the weekly rules themselves:
     *   - the classification has no visiting that weekday (lists the days it has);
     *   - it has rules that weekday, but none in effect on this date;
     *   - that weekday is in effect, but not for the chosen session.
     * Only the wording differs; the request is refused the same way in each case.
     */
    private function noRuleMessage(string $classification, CarbonImmutable $date, string $session): string
    {
        // $classification is a rule group: a classification or "dorm:{id}".
        $group = str_replace(' PDLs', '', FacilityVisitationRule::groupLabel($classification));
        $isDorm = str_starts_with($classification, 'dorm:');
        $weekday = $date->format('l').'s';
        $day = $date->toDateString();
        $inEffect = fn (FacilityVisitationRule $rule) => (! $rule->effective_from || $day >= $rule->effective_from->toDateString())
            && (! $rule->effective_to || $day <= $rule->effective_to->toDateString());

        $rules = FacilityVisitationRule::forGroup($classification)->get();
        $dayRules = $rules->where('day_of_week', self::DAY_KEYS[$date->dayOfWeek]);

        if ($dayRules->isEmpty()) {
            $days = $rules->filter($inEffect)
                ->pluck('day_of_week')
                ->unique()
                ->sortBy(fn ($key) => (array_search($key, self::DAY_KEYS, true) + 6) % 7) // Monday first
                ->map(fn ($key) => self::DAY_NAMES[array_search($key, self::DAY_KEYS, true)])
                ->values();

            return ($isDorm ? "{$group} has" : "{$group} PDLs have")." no visiting on {$weekday}. ".($days->isEmpty()
                ? 'No visiting days are currently configured for them.'
                : 'Their configured visiting days are '.$days->join(', ', ' and ').'.');
        }

        if ($dayRules->filter($inEffect)->isEmpty()) {
            return 'Visiting for '.($isDorm ? $group : lcfirst($group).' PDLs')." on {$weekday} is not in effect on {$date->format('M j, Y')}.";
        }

        $sessions = $dayRules->filter($inEffect)
            ->sortBy(fn ($rule) => (string) $rule->time_slot_start)
            ->map(fn ($rule) => self::sessionOf($rule->time_slot_start))
            ->unique()
            ->values();

        return "There is no {$session} visiting session for ".($isDorm ? $group : lcfirst($group).' PDLs')." on {$weekday}. "
            .'Sessions configured that day: '.$sessions->join(', ', ' and ').'.';
    }

    private function findRow(FacilityVisitationRule $rule, CarbonImmutable $date): ?VisitSchedule
    {
        return VisitSchedule::where('rule_id', $rule->rule_id)
            ->whereDate('schedule_date', $date->toDateString())
            ->where('time_slot_start', $rule->time_slot_start)
            ->first();
    }

    private function assertCapacityInRange(int $capacity): void
    {
        if ($capacity < self::MIN_CAPACITY || $capacity > self::MAX_CAPACITY) {
            throw ValidationException::withMessages([
                'max_capacity' => 'Capacity must be between '.self::MIN_CAPACITY.' and '.self::MAX_CAPACITY.'.',
            ]);
        }
    }

    /** "Oct 16, 2026 09:00–11:30 (non-drug-related)" */
    private function label(VisitSchedule $schedule): string
    {
        $schedule->loadMissing('rule');
        $group = $schedule->rule?->pdl_classification === 'drug_related' ? 'drug-related' : 'non-drug-related';

        return $schedule->schedule_date->format('M j, Y').' '
            .substr((string) $schedule->time_slot_start, 0, 5).'–'.substr((string) $schedule->time_slot_end, 0, 5)
            ." ({$group})";
    }
}
