<?php

namespace App\Services;

use App\Http\Controllers\FrontDesk\WalkInController;
use App\Models\VisitCheckin;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;

/**
 * Front Desk sidebar badges: live counts of what the gate still has to do,
 * in the same blue pill as the Admin sidebar (AdminNavBadges).
 *
 * Each badge is null when the count is 0. The Front Desk layout polls
 * GET front-desk/nav-badges, and clicking a badge opens the items it counts.
 */
class FrontDeskNavBadges
{
    public function __construct(private WalkInService $walkIns) {}

    /** @return array<string, ?array{value: int, title: string, url: ?string}> keyed by sidebar item */
    public function all(): array
    {
        return [
            'checkin' => $this->badge(VisitCheckin::where('status', 'checked_in')->count(),
                fn ($n) => "{$n} " . ($n === 1 ? 'visitor is' : 'visitors are') . ' inside now, to check out',
                route('frontdesk.schedule', 'inside')),
            'walk-ins' => $this->badge($this->pendingWalkIns(),
                fn ($n) => "{$n} " . ($n === 1 ? 'walk-in is' : 'walk-ins are') . ' waiting for your decision',
                route('frontdesk.walk-ins.index', ['status' => 'pending'])),
            'schedule' => $this->badge($this->arrivingToday(),
                fn ($n) => "{$n} approved " . ($n === 1 ? 'visit' : 'visits') . ' today, not arrived yet',
                route('frontdesk.schedule', ['expected', 'date' => today()->format('Y-m-d')])),
        ];
    }

    private function badge(int $value, callable $title, ?string $url = null): ?array
    {
        return $value > 0 ? ['value' => $value, 'title' => $title($value), 'url' => $url] : null;
    }

    /** Approved visits booked for today with nobody at the gate yet. */
    private function arrivingToday(): int
    {
        return VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', today()))
            ->whereDoesntHave('checkins')
            ->count();
    }

    /**
     * Walk-ins on the Pending Approval tab. Rejected walk-ins and arrivals
     * already decided (declined, or approved with a visit booked) are left
     * out in SQL; the rest go through the same status as the walk-in list.
     */
    private function pendingWalkIns(): int
    {
        $pendingKeys = WalkInController::CARDS['pending'][4];

        return VisitorProfile::where('registered_via', 'walk_in')
            ->where('verification_status', '!=', 'rejected')
            ->whereDoesntHave('walkInAssessments', fn ($q) => $q
                ->where(fn ($done) => $done->where('decision', 'declined')->orWhereNotNull('visit_request_id'))
                ->whereRaw('walk_in_assessments.assessment_id = (select max(a.assessment_id) from walk_in_assessments a where a.visitor_id = walk_in_assessments.visitor_id)'))
            ->get()
            ->filter(fn (VisitorProfile $visitor) => in_array($this->walkIns->status($visitor)['key'], $pendingKeys, true))
            ->count();
    }
}
