<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\ActivityLog;
use App\Support\Sanitizer;
use Illuminate\Http\Request;

/**
 * Record Officer activity log: every logged action in the Records area
 * (visitor accounts, relationships, visits/eligibility, flags, PDL records,
 * cell blocks), newest first, with search and filters. Read-only — entries
 * are written by AuditLog::record() and can't be edited or deleted.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:' . implode(',', array_keys(ActivityLog::CATEGORIES))],
            'who' => ['nullable', 'in:all,me'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $q = Sanitizer::text($filters['q'] ?? '') ?? '';
        $category = $filters['category'] ?? null;
        $who = $filters['who'] ?? 'all';

        $logs = AuditLog::query()
            ->with('account.role')
            ->whereIn('record_type', $category ? ActivityLog::CATEGORIES[$category]['types'] : ActivityLog::allTypes())
            ->when($q !== '', fn ($query) => $query->where('description', 'like', Sanitizer::like($q)))
            ->when($who === 'me', fn ($query) => $query->where('account_id', $request->user()->getKey()))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('audit_log_id')
            ->paginate(10)
            ->withQueryString();

        $links = ActivityLog::links($logs->getCollection());

        return view('activity-log.index', [
            'logs' => $logs,
            'links' => $links,
            'q' => $q,
            'category' => $category,
            'who' => $who,
            'from' => $filters['from'] ?? '',
            'to' => $filters['to'] ?? '',
        ]);
    }
}
