<?php

namespace App\Services;

use App\Models\FacilityVisitationRule;
use App\Models\VisitCheckin;
use App\Models\VisitRequest;
use App\Models\VisitSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Front-desk gate rules for a visit and its sessions.
 *
 * One QR identifies the whole visit. Each session of the visit is entered at
 * most once and has its own gate record (visit_checkins row):
 *
 *   morning check-in -> midday exit (visit stays confirmed, QR stays active)
 *                    -> afternoon re-entry (QR used: no session left to enter)
 *                    -> final check-out (visit completed)
 *
 * Leaving after the afternoon session has started is the final check-out,
 * not a midday exit: the visit is completed and there is no re-entry.
 *
 * A session can be entered until it ends. The first session of the day opens
 * EARLY_ENTRY_MINUTES before its start (08:30 for a 09:00 morning). A later
 * session of the day opens at its own start (the afternoon session opens at
 * 13:00, not during the midday break), so neither a whole-day visitor
 * returning from the midday exit nor an afternoon-only visitor can come in
 * before it.
 *
 * A visitor is inside for only one visit at a time: check-in is refused
 * while they have an open gate record on another visit (another PDL today,
 * or an exit left unrecorded on an earlier day). Check-out is never blocked.
 */
class VisitGateService
{
    /** How early before the first session of the day check-in opens. */
    public const EARLY_ENTRY_MINUTES = 30;

    /** The gate record of a visitor of this visit who is inside now. */
    public function activeCheckin(VisitRequest $visit): ?VisitCheckin
    {
        return $visit->checkins()->where('status', 'checked_in')->orderByDesc('checkin_id')->first();
    }

    /**
     * An open gate record of this visit's visitor on a DIFFERENT visit (a
     * visit for another PDL today, or an exit left unrecorded on an earlier
     * day), or null. An ordinary read: inside a check-in transaction it sees
     * a check-in committed a moment ago because the controller locks the
     * visitor row before the transaction's first ordinary read (see
     * CheckinCheckoutController::lockVisitor()). Not a locking read: with no
     * index on status, FOR UPDATE would lock every scanned gate record and
     * gap, blocking all other check-ins and check-outs and letting two
     * concurrent check-ins deadlock on the insert.
     */
    public function visitorInsideElsewhere(VisitRequest $visit): ?VisitCheckin
    {
        if ($visit->visitor_id === null) {
            return null;
        }

        return VisitCheckin::where('status', 'checked_in')
            ->where('visit_request_id', '!=', $visit->visit_request_id)
            ->whereHas('visitRequest', fn ($q) => $q->where('visitor_id', $visit->visitor_id))
            ->orderByDesc('checkin_id')
            ->first();
    }

    /**
     * Why this visit's visitor cannot be checked in because they are marked
     * inside for another visit, or null when they are not. Check-out never
     * uses this.
     */
    public function visitorInsideElsewhereMessage(VisitRequest $visit): ?string
    {
        $other = $this->visitorInsideElsewhere($visit);

        if (! $other) {
            return null;
        }

        if ($other->isFromPreviousDay()) {
            return 'This visitor is still marked inside from a previous visit on '
                .$other->check_in_time->format('M j, Y')
                .'. Close that record first under "Still marked inside from a previous day" (Close record – exit not recorded), then check them in.';
        }

        $pdl = $other->visitRequest?->pdl?->full_name;

        return 'This visitor is already inside for another visit (visit #'.$other->visit_request_id
            .($pdl ? ", PDL {$pdl}" : '')
            .'). Check them out of that visit first.';
    }

    /**
     * Sessions of the visit not yet entered and not yet ended, earliest first.
     * Uses the visit's checkins / sessionSchedules when already loaded (page
     * lists that eager-load them); otherwise reads them from the database,
     * as the gate's check-in and check-out transactions do.
     *
     * @return Collection<int, VisitSchedule>
     */
    public function remainingSessions(VisitRequest $visit, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $entered = ($visit->relationLoaded('checkins')
                ? $visit->checkins->pluck('schedule_id')
                : $visit->checkins()->pluck('schedule_id'))
            ->map(fn ($id) => (int) $id)->all();

        $sessions = $visit->relationLoaded('sessionSchedules')
            ? $visit->sessionSchedules->loadMissing('rule')
            : $visit->sessionSchedules()->with('rule')->get();

        return $sessions
            ->reject(fn (VisitSchedule $s) => in_array((int) $s->schedule_id, $entered, true))
            ->filter(fn (VisitSchedule $s) => $now->lt($this->endsAt($s)))
            ->values();
    }

    /**
     * The session the visitor may enter now, or why none can be.
     *
     * @return array{0: VisitSchedule|null, 1: string|null} [session, error]
     */
    public function enterableSession(VisitRequest $visit, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $next = $this->remainingSessions($visit, $now)->first();

        if (! $next) {
            return [null, $visit->checkins()->exists()
                ? 'This visit has no remaining session to enter today.'
                : 'This visit\'s session has already ended for today.'];
        }

        $opensAt = $this->opensAt($next);
        if ($now->lt($opensAt)) {
            return [null, "This visit is for the {$this->sessionName($next)} ({$this->rangeLabel($next)}). Entry opens at {$opensAt->format('H:i')}."];
        }

        return [$next, null];
    }

    /**
     * Whether leaving $checkin's session is a temporary (midday) exit: the
     * visit has a later session that was not entered and has not started
     * yet. Leaving once that session has started (e.g. 14:00 for a 13:00
     * afternoon) is the final check-out: the visitor stayed through it.
     */
    public function hasReturnSession(VisitRequest $visit, VisitCheckin $checkin, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $current = $checkin->schedule;
        if (! $current) {
            return false;
        }

        return $this->remainingSessions($visit, $now)
            ->contains(fn (VisitSchedule $s) => (string) $s->time_slot_start > (string) $current->time_slot_start
                && $now->lt($this->startsAt($s)));
    }

    /**
     * Why the visit's PDL cannot be visited today (left custody, or a
     * restriction covering today), or null when they can. Read from the
     * database so a change a moment ago is seen. Check-out never uses this.
     */
    public function pdlUnavailableMessage(VisitRequest $visit, ?CarbonInterface $now = null): ?string
    {
        $pdl = \App\Models\Pdl::find($visit->pdl_id);

        return $pdl && $pdl->isVisitableOn(($now ?? now())->toDateString())
            ? null
            : self::PDL_UNAVAILABLE_MESSAGE;
    }

    public const PDL_UNAVAILABLE_MESSAGE =
        'This PDL is not available for visitation today. Do not admit the visitor; refer them to the Warden.';

    /**
     * End of the visit's last session (11:30 for a morning visit, 16:30 for
     * an afternoon or whole-day one) — when a pass expires and after which
     * a visitor still inside has overstayed. Null without a schedule.
     */
    public function lastSessionEnd(VisitRequest $visit): ?Carbon
    {
        $sessions = $visit->relationLoaded('sessionSchedules')
            ? $visit->sessionSchedules->sortBy(fn ($s) => (string) $s->time_slot_start)
            : $visit->sessionSchedules()->get();
        $last = $sessions->last() ?? $visit->schedule;

        return $last ? $this->endsAt($last) : null;
    }

    /**
     * Whether a visitor of this visit is inside after the visit's last
     * session ended. Uses the gate records' check-in/check-out state only.
     */
    public function isOverstayed(VisitRequest $visit, ?CarbonInterface $now = null): bool
    {
        $inside = $visit->relationLoaded('checkins')
            ? $visit->checkins->contains('status', 'checked_in')
            : $visit->checkins()->where('status', 'checked_in')->exists();
        $end = $inside ? $this->lastSessionEnd($visit) : null;

        return $end !== null && ($now ?? now())->gt($end);
    }

    /** Whether $session is the visit's last session (no later one to enter). */
    public function isLastSession(VisitRequest $visit, VisitSchedule $session): bool
    {
        return ! $visit->sessionSchedules()
            ->where('visit_schedules.time_slot_start', '>', $session->time_slot_start)
            ->exists();
    }

    /** "Morning session" / "Afternoon session" */
    public function sessionName(VisitSchedule $session): string
    {
        return ((int) substr((string) $session->time_slot_start, 0, 2) < 12 ? 'morning' : 'afternoon').' session';
    }

    /** "13:00 - 16:30" */
    public function rangeLabel(VisitSchedule $session): string
    {
        return Carbon::parse((string) $session->time_slot_start)->format('H:i')
            .' - '.Carbon::parse((string) $session->time_slot_end)->format('H:i');
    }

    private function startsAt(VisitSchedule $session): Carbon
    {
        return Carbon::parse($session->schedule_date->toDateString().' '.$session->time_slot_start);
    }

    private function endsAt(VisitSchedule $session): Carbon
    {
        return Carbon::parse($session->schedule_date->toDateString().' '.$session->time_slot_end);
    }

    /**
     * When check-in for $session opens: EARLY_ENTRY_MINUTES before it starts
     * when it is the facility's first session that day for the same visiting
     * rule set; otherwise at its own start, so the midday break stays closed.
     */
    private function opensAt(VisitSchedule $session): Carbon
    {
        $opensAt = $this->startsAt($session)->subMinutes(self::EARLY_ENTRY_MINUTES);

        $rule = $session->rule;
        if (! $rule) {
            return $opensAt;
        }

        $hasEarlierSession = FacilityVisitationRule::forGroup($rule->groupKey())
            ->where('day_of_week', $rule->day_of_week)
            ->where('time_slot_end', '<=', $session->time_slot_start)
            ->exists();

        return $hasEarlierSession ? $this->startsAt($session) : $opensAt;
    }
}
