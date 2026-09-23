<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\View\View;

/**
 * Module 1.7 Audit Trail.
 *
 * Reads from the same session-backed log every other admin module writes
 * to via App\Support\AuditLog — so actions you take elsewhere (adding a
 * PDL, checking a visitor in, approving a visit request, ...) show up
 * here too. Resets whenever the session resets, since nothing is backed
 * by a real database yet.
 */
class AuditController extends Controller
{
    public function index(): View
    {
        $auditLogs = AuditLog::all(self::defaultLogs());

        $summary = [
            ['label' => 'Log Entries', 'value' => (string) count($auditLogs), 'icon' => 'clipboard', 'accent' => 'info'],
        ];

        return view('admin.audit.index', compact('auditLogs', 'summary'));
    }

    private static function defaultLogs(): array
    {
        return [
            ['account' => 'r.salcedo@bjmp.gov.ph', 'action_type' => 'update', 'module' => 'PDL Management', 'description' => 'Updated disciplinary record for PDL-0055', 'created_at' => '2026-09-22 09:40'],
            ['account' => 'n.fernandez@bjmp.gov.ph', 'action_type' => 'check_in', 'module' => 'QR Check-In and Check-Out', 'description' => 'Checked in visitor for VIS-2026-0922-001', 'created_at' => '2026-09-22 09:04'],
            ['account' => 'g.manalo@bjmp.gov.ph', 'action_type' => 'approve', 'module' => 'Visitor Eligibility Assessment', 'description' => 'Approved visitor Maria D. Santos after manual review', 'created_at' => '2026-09-21 16:12'],
            ['account' => 'a.domingo@bjmp.gov.ph', 'action_type' => 'update', 'module' => 'PDL Management', 'description' => 'Lifted quarantine restriction on PDL-0055', 'created_at' => '2026-09-21 14:20'],
            ['account' => 'system', 'action_type' => 'deny', 'module' => 'Visitor Eligibility Assessment', 'description' => 'Auto-rejected visitor Dante R. Cabrera — unresolved rule violation flag', 'created_at' => '2026-09-21 10:05'],
        ];
    }
}
