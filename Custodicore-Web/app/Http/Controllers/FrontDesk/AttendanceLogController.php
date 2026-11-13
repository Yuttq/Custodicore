<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\VisitCheckin;
use App\Support\Sanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Activity Log — for one day (today by default): every gate entry
 * (check-in / check-out), filtered by search and check-in method, and
 * All Activity: every logged action of that day done by a Front Desk
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

    public function index(Request $request): View
    {
        $date = $this->parseDay((string) $request->input('date', '')) ?? today();

        // Cleaned and capped; matched literally, so % and _ are not wildcards.
        $query = mb_substr(Sanitizer::text((string) $request->input('q', '')) ?? '', 0, 100);
        $method = array_key_exists((string) $request->input('method'), self::METHODS) ? (string) $request->input('method') : '';

        $entries = VisitCheckin::whereNotNull('check_in_time')
            ->whereDate('check_in_time', $date)
            ->when($method !== '', fn ($q) => $q->where('verification_method', $method))
            ->when($query !== '', function ($q) use ($query) {
                $like = Sanitizer::like($query);
                $q->where(fn ($match) => $match
                    ->whereHas('visitRequest', fn ($visit) => $visit->where(fn ($people) => $people
                        ->whereHas('visitor', fn ($v) => $v->where('full_name', 'like', $like))
                        ->orWhereHas('pdl', fn ($p) => $p->where('full_name', 'like', $like)->orWhere('pdl_number', 'like', $like))))
                    ->orWhereHas('checkInOfficer', fn ($o) => $o->where('full_name', 'like', $like))
                    ->orWhereHas('checkOutOfficer', fn ($o) => $o->where('full_name', 'like', $like)));
            })
            ->with(['visitRequest.visitor', 'visitRequest.pdl', 'checkInOfficer', 'checkOutOfficer'])
            ->orderByDesc('check_in_time')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $activity = AuditLog::query()
            ->with(['account.role', 'account.staffProfile', 'account.visitorProfile'])
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
            })
            ->orderByDesc('created_at')
            ->orderByDesc('audit_log_id')
            ->paginate(self::PER_PAGE, ['*'], 'activity_page')
            ->withQueryString()
            ->fragment('all-activity');

        return view('frontdesk.attendance-log', [
            'entries' => $entries,
            'activity' => $activity,
            'date' => $date,
            'query' => $query,
            'method' => $method,
            'filtered' => $query !== '' || $method !== '' || ! $date->isToday(),
        ]);
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
