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
 * A session can be entered until it ends. A later session of the day opens
 * at its own start (the afternoon session opens at 13:00, not during the
 * midday break), so neither a whole-day visitor returning from the midday
 * exit nor an afternoon-only visitor can come in before it. The first session
 * of the day has no opening bound.
 */
class VisitGateService
{
    /** The gate record of a visitor of this visit who is inside now. */
    public function activeCheckin(VisitRequest $visit): ?VisitCheckin
    {
        return $visit->checkins()->where('status', 'checked_in')->orderByDesc('checkin_id')->first();
    }

    /**
     * Sessions of the visit not yet entered and not yet ended, earliest first.
     *
     * @return Collection<int, VisitSchedule>
     */
    public function remainingSessions(VisitRequest $visit, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $entered = $visit->checkins()->pluck('schedule_id')->map(fn ($id) => (int) $id)->all();

        return $visit->sessionSchedules()->with('rule')->get()
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
        if ($opensAt && $now->lt($opensAt)) {
            return [null, "This visit is for the {$this->sessionName($next)} ({$this->rangeLabel($next)}). Entry opens at {$opensAt->format('h:i A')}."];
        }

        return [$next, null];
    }

    /**
     * Whether leaving $checkin's session is a temporary (midday) exit: the
     * visit has a later session that was not entered and has not ended.
     */
    public function hasReturnSession(VisitRequest $visit, VisitCheckin $checkin, ?CarbonInterface $now = null): bool
    {
        $current = $checkin->schedule;
        if (! $current) {
            return false;
        }

        return $this->remainingSessions($visit, $now)
            ->contains(fn (VisitSchedule $s) => (string) $s->time_slot_start > (string) $current->time_slot_start);
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

    /** "01:00 PM - 04:30 PM" */
    public function rangeLabel(VisitSchedule $session): string
    {
        return Carbon::parse((string) $session->time_slot_start)->format('h:i A')
            .' - '.Carbon::parse((string) $session->time_slot_end)->format('h:i A');
    }

    private function endsAt(VisitSchedule $session): Carbon
    {
        return Carbon::parse($session->schedule_date->toDateString().' '.$session->time_slot_end);
    }

    /**
     * Start of $session when the facility has an earlier session that day for
     * the same visiting rule set; the first session of the day has no bound.
     */
    private function opensAt(VisitSchedule $session): ?Carbon
    {
        $rule = $session->rule;
        if (! $rule) {
            return null;
        }

        $hasEarlierSession = FacilityVisitationRule::where('pdl_classification', $rule->pdl_classification)
            ->where('day_of_week', $rule->day_of_week)
            ->where('time_slot_end', '<=', $session->time_slot_start)
            ->exists();

        return $hasEarlierSession
            ? Carbon::parse($session->schedule_date->toDateString().' '.$session->time_slot_start)
            : null;
    }
}
