<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\VisitCheckin;
use App\Support\Sanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Activity Log — one day (today by default), in two tabs sharing the
 * search and day. Gate Visits: one row per gate entry (check-in /
 * check-out, ID held, status), filtered by check-in method. All Activity:
 * every logged action of that day, filtered by type, done by a Front Desk
 * officer, plus anything else that happened to walk-ins and gate entries
 * (Records' walk-in decisions, closed records). Read-only; entries are
 * written by AuditLog::record().
 */
class AttendanceLogController extends Controller
{
    private const PER_PAGE = 25;

    /** record_type => label of the All Activity entries. */
    public const ACTIVITY_TYPES = [
        'visit_checkins' => 'Gate',
        'walk_in_assessments' => 'Walk-in',
        'visitor_profiles' => 'Visitor',
        'visit_requests' => 'Visit',
        'visitor_ids' => 'ID',
        'visitor_pdl_relationships' => 'Relationship',
        'relationship_documents' => 'Document',
    ];

    /** Shown for every Front Desk officer's entries; these also from anyone else. */
    private const ALWAYS_SHOWN = ['visit_checkins', 'walk_in_assessments'];

    /** ?method= => verification_method. */
    public const METHODS = ['qr_scan' => 'QR Scan', 'manual_override' => 'Manual'];

    /** ?tab= => label. The first one is the default. */
    public const TABS = ['gate' => 'Gate Visits', 'activity' => 'All Activity'];

    public function index(Request $request): View
    {
        $date = $this->parseDay((string) $request->input('date', '')) ?? today();
        $tab = array_key_exists((string) $request->input('tab'), self::TABS) ? (string) $request->input('tab') : 'gate';

        // Cleaned and capped; matched literally, so % and _ are not wildcards.
        $query = mb_substr(Sanitizer::text((string) $request->input('q', '')) ?? '', 0, 100);
        $method = array_key_exists((string) $request->input('method'), self::METHODS) ? (string) $request->input('method') : '';
        $type = array_key_exists((string) $request->input('type'), self::ACTIVITY_TYPES) ? (string) $request->input('type') : '';

        // Tab counts follow the shared day and search, not the tab's own filter.
        $counts = [
            'gate' => $this->gateQuery($date, $query)->count(),
            'activity' => $this->activityQuery($date, $query)->count(),
        ];

        $list = $tab === 'gate'
            ? $this->gateQuery($date, $query)
                ->when($method !== '', fn ($q) => $q->where('verification_method', $method))
                ->with(['visitRequest.visitor', 'visitRequest.pdl', 'checkInOfficer', 'checkOutOfficer'])
                ->orderByDesc('check_in_time')
            : $this->activityQuery($date, $query)
                ->when($type !== '', fn ($q) => $q->where('record_type', $type))
                ->with(['account.role', 'account.staffProfile', 'account.visitorProfile'])
                ->orderByDesc('created_at')
                ->orderByDesc('audit_log_id');

        return view('frontdesk.attendance-log', [
            'tab' => $tab,
            'counts' => $counts,
            'list' => $list->paginate(self::PER_PAGE)->withQueryString(),
            'date' => $date,
            'query' => $query,
            'method' => $method,
            'type' => $type,
            'filtered' => $query !== '' || $method !== '' || $type !== '' || ! $date->isToday(),
        ]);
    }

    /** That day's gate entries, matching the search by visitor, PDL or officer. */
    private function gateQuery(Carbon $date, string $query): Builder
    {
        return VisitCheckin::whereNotNull('check_in_time')
            ->whereDate('check_in_time', $date)
            ->when($query !== '', function ($q) use ($query) {
                $like = Sanitizer::like($query);
                $q->where(fn ($match) => $match
                    ->whereHas('visitRequest', fn ($visit) => $visit->where(fn ($people) => $people
                        ->whereHas('visitor', fn ($v) => $v->where('full_name', 'like', $like))
                        ->orWhereHas('pdl', fn ($p) => $p->where('full_name', 'like', $like)->orWhere('pdl_number', 'like', $like))))
                    ->orWhereHas('checkInOfficer', fn ($o) => $o->where('full_name', 'like', $like))
                    ->orWhereHas('checkOutOfficer', fn ($o) => $o->where('full_name', 'like', $like)));
            });
    }

    /** That day's logged actions, matching the search by description or who did it. */
    private function activityQuery(Carbon $date, string $query): Builder
    {
        return AuditLog::query()
            ->whereDate('created_at', $date)
            ->where(fn ($q) => $q
                ->whereHas('account.role', fn ($role) => $role->where('role_name', 'Front Desk Officer'))
                ->orWhereIn('record_type', self::ALWAYS_SHOWN))
            ->when($query !== '', function ($q) use ($query) {
                $like = Sanitizer::like($query);
                $q->where(fn ($match) => $match
                    ->where('description', 'like', $like)
                    ->orWhereHas('account', fn ($a) => $a->where('username', 'like', $like)
                        ->orWhereHas('staffProfile', fn ($staff) => $staff->where('full_name', 'like', $like))));
            });
    }

    /** A Y-m-d day that is real and not in the future, or null (then today). */
    private function parseDay(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value && ! $date->isFuture() ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
