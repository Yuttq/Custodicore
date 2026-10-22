<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\DateSearch;
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
 *
 * One search box does everything: type a date, month or year ("2026-10-20",
 * "October 2026", "2026") or a time ("21:58") and every action in that period
 * is listed; type anything else and it searches names, actions, modules and
 * descriptions. See App\Support\DateSearch for the accepted spellings.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $search = Sanitizer::text($request->query('q'));
        $parsed = DateSearch::parse($search);

        // Words to look for (null when the box held a date / time).
        $words = $parsed['text'];
        $like = $words !== null ? Sanitizer::like($words) : null;
        // "check in" typed with a space should still find the check_in action.
        $likeAction = $words !== null ? Sanitizer::like(str_replace(' ', '_', $words)) : null;

        $filtered = $search !== null;

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
            ->when($parsed['active'], fn ($query) => DateSearch::apply($query, 'created_at', $parsed))
            ->orderByDesc('created_at')
            ->orderByDesc('audit_log_id')
            ->take(2000)
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

        $dateLabel = $parsed['label'];
        $auditLogs = Pages::of($auditLogs);

        return view('admin.audit.index', compact('auditLogs', 'summary', 'search', 'totalLogs', 'filtered', 'dateLabel'));
    }
}
