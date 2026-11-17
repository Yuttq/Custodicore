<?php

namespace App\Services;

use App\Models\Account;
use App\Models\VisitCheckin;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;

/**
 * Admin (Warden) sidebar badges: live counts, always blue, so the Admin sees
 * at a glance which sections have something going on. Same idea as the
 * Records Officer badges (App\Services\NavBadges).
 *
 * Each badge is null when the count is 0. The admin layout polls
 * GET admin/nav-badges, and clicking a badge opens the items it counts.
 */
class AdminNavBadges
{
    private const DAYS_AHEAD = 7;

    /** @return array<string, ?array{value: int, title: string, url: ?string}> keyed by sidebar item */
    public function all(): array
    {
        return [
            'dashboard' => $this->badge($this->visitsToday(),
                fn ($n) => "{$n} confirmed " . ($n === 1 ? 'visit' : 'visits') . ' today',
                route('admin.dashboard')),
            'users' => $this->badge($this->awaitingSetup(),
                fn ($n) => "{$n} " . ($n === 1 ? 'officer has' : 'officers have') . ' not finished setting up their account',
                route('admin.users.index', ['filter' => 'awaiting'])),
            'pdls' => $this->badge(NavBadges::hearingsAheadQuery()->count(),
                fn ($n) => "{$n} court " . ($n === 1 ? 'hearing' : 'hearings') . ' in the next ' . self::DAYS_AHEAD . ' days',
                route('admin.pdls.index')),
            'visitors' => $this->badge(VisitorProfile::where('verification_status', 'pending')->count(),
                fn ($n) => "{$n} " . ($n === 1 ? 'visitor is' : 'visitors are') . ' waiting for verification',
                route('admin.visitors.index', ['filter' => 'pending'])),
            'checkins' => $this->badge(VisitCheckin::where('status', 'checked_in')->count(),
                fn ($n) => "{$n} " . ($n === 1 ? 'visitor is' : 'visitors are') . ' inside the facility now',
                route('admin.checkins.index', ['filter' => 'inside', 'all' => 1])),
        ];
    }

    private function badge(int $value, callable $title, ?string $url = null): ?array
    {
        return $value > 0 ? ['value' => $value, 'title' => $title($value), 'url' => $url] : null;
    }

    private function visitsToday(): int
    {
        return VisitRequest::query()
            ->where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', now()->toDateString()))
            ->count();
    }

    /** Staff accounts whose setup link has not been used yet (pending or expired). */
    private function awaitingSetup(): int
    {
        return Account::query()->whereNotNull('invitation_token_hash')->count();
    }
}
