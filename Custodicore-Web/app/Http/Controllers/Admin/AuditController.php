<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Pages;
use App\Support\Sanitizer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 1.7 Audit Trail.
 *
 * Reads the real audit_logs table now — the same one every module
 * (Admin/Warden, Records Officer, Front Desk) writes to via
 * App\Models\AuditLog::record(), so actions taken anywhere in the system
 * show up here together, tagged with the module they actually happened in.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $search = Sanitizer::text($request->query('q'));
        $like = $search !== null ? Sanitizer::like($search) : null;
        // "check in" typed with a space should still find the check_in action.
        $likeAction = $search !== null ? Sanitizer::like(str_replace(' ', '_', $search)) : null;

        // Date range (inclusive). Invalid dates are ignored; a reversed
        // range is swapped rather than returning nothing.
        $from = $this->dateParam($request->query('from'));
        $to = $this->dateParam($request->query('to'));
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        // Time of day (24-hour "HH:MM"), applied to every day in the range.
        // If "from" is later than "to" the window runs overnight (e.g. 22:00–06:00).
        $fromTime = $this->timeParam($request->query('from_time'));
        $toTime = $this->timeParam($request->query('to_time'));
        $filtered = $search !== null || $from || $to || $fromTime || $toTime;

        $auditLogs = AuditLog::query()
            ->with(['account.staffProfile', 'account.visitorProfile', 'module'])
            ->when($like, function ($query) use ($like, $likeAction) {
                $query->where(function ($q) use ($like, $likeAction) {
                    $q->where('description', 'like', $like)
                        ->orWhere('action_type', 'like', $likeAction)
                        ->orWhere('record_type', 'like', $like)
                        ->orWhereHas('account', fn ($a) => $a->where('email', 'like', $like)
                            ->orWhere('username', 'like', $like)
                            ->orWhereHas('staffProfile', fn ($p) => $p->where('full_name', 'like', $like))
                            ->orWhereHas('visitorProfile', fn ($p) => $p->where('full_name', 'like', $like)))
                        ->orWhereHas('module', fn ($m) => $m->where('module_name', 'like', $like));
                });
            })
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->when($fromTime || $toTime, function ($query) use ($fromTime, $toTime) {
                $start = $fromTime ? $fromTime . ':00' : '00:00:00';
                $end = $toTime ? $toTime . ':59' : '23:59:59';
                if ($start <= $end) {
                    $query->whereTime('created_at', '>=', $start)->whereTime('created_at', '<=', $end);
                } else {
                    // Overnight window: late evening OR early morning.
                    $query->where(fn ($q) => $q->whereTime('created_at', '>=', $start)->orWhereTime('created_at', '<=', $end));
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('audit_log_id')
            ->take(500)
            ->get()
            ->map(fn ($log) => [
                'created_at' => $log->created_at?->format('Y-m-d H:i'),
                // The person's name (staff, or visitor for visitor actions) — never the email.
                'account' => $log->account ? $log->account->displayName() : 'System',
                'is_system' => $log->account === null,
                'action_type' => $log->action_type,
                'module' => $log->module?->module_name ?? '—',
                'description' => $log->description,
            ])
            ->all();

        // The card always counts every entry, not just the search result.
        $totalLogs = AuditLog::count();

        $summary = [
            ['label' => 'Log Entries', 'value' => (string) $totalLogs, 'icon' => 'clipboard', 'accent' => 'info'],
        ];

        $auditLogs = Pages::of($auditLogs);

        return view('admin.audit.index', compact('auditLogs', 'summary', 'search', 'totalLogs', 'from', 'to', 'fromTime', 'toTime', 'filtered'));
    }

    /** "HH:MM" (24-hour) if it is a real time of day, otherwise null. */
    private function timeParam(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value)) ? trim($value) : null;
    }

    /** "YYYY-MM-DD" if it's a real calendar date, otherwise null. */
    private function dateParam(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
