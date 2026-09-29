<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
    public function index(): View
    {
        $auditLogs = AuditLog::query()
            ->with(['account.staffProfile', 'module'])
            ->orderByDesc('created_at')
            ->take(100)
            ->get()
            ->map(fn ($log) => [
                'created_at' => $log->created_at?->format('Y-m-d H:i'),
                'account' => $log->account?->email ?? 'system',
                'action_type' => $log->action_type,
                'module' => $log->module?->module_name ?? '—',
                'description' => $log->description,
            ])
            ->all();

        $summary = [
            ['label' => 'Log Entries', 'value' => (string) count($auditLogs), 'icon' => 'clipboard', 'accent' => 'info'],
        ];

        return view('admin.audit.index', compact('auditLogs', 'summary'));
    }
}
