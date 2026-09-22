<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** Module 1.7 Audit Trail and Basic Reporting. */
class AuditController extends Controller
{
    public function index(): View
    {
        $auditLogs = [
            ['account' => 'r.salcedo@bjmp.gov.ph', 'action_type' => 'update', 'module' => 'PDL Management', 'description' => 'Updated disciplinary record for PDL-0055', 'created_at' => '2026-09-22 09:40'],
            ['account' => 'n.fernandez@bjmp.gov.ph', 'action_type' => 'check_in', 'module' => 'QR Check-In and Check-Out', 'description' => 'Checked in visitor for VIS-2026-0922-001', 'created_at' => '2026-09-22 09:04'],
            ['account' => 'g.manalo@bjmp.gov.ph', 'action_type' => 'approve', 'module' => 'Visitor Eligibility Assessment', 'description' => 'Approved visitor Maria D. Santos after manual review', 'created_at' => '2026-09-21 16:12'],
            ['account' => 'a.domingo@bjmp.gov.ph', 'action_type' => 'update', 'module' => 'PDL Management', 'description' => 'Lifted quarantine restriction on PDL-0055', 'created_at' => '2026-09-21 14:20'],
            ['account' => 'system', 'action_type' => 'deny', 'module' => 'Visitor Eligibility Assessment', 'description' => 'Auto-rejected visitor Dante R. Cabrera — unresolved rule violation flag', 'created_at' => '2026-09-21 10:05'],
        ];

        $reports = [
            ['report_type' => 'visitation_trends', 'date_range' => 'Aug 1 – Aug 31, 2026', 'generated_by' => 'Ana R. Domingo', 'generated_at' => '2026-09-01'],
            ['report_type' => 'peak_hours', 'date_range' => 'Jul 1 – Jul 31, 2026', 'generated_by' => 'Rico P. Salcedo', 'generated_at' => '2026-08-02'],
            ['report_type' => 'pdl_visit_patterns', 'date_range' => 'Jun 1 – Jun 30, 2026', 'generated_by' => 'Ana R. Domingo', 'generated_at' => '2026-07-03'],
        ];

        return view('admin.audit.index', compact('auditLogs', 'reports'));
    }
}
