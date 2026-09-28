<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Pdl;

class CustodyHistoryController extends Controller
{
    public function index()
    {
        // Filtered to the record_type our own PdlController writes when
        // custody_status/cell_block changes — see the NOTE in
        // PdlController::update(). This is an application-level convention,
        // not something enforced by the database schema itself.
        $entries = AuditLog::where('record_type', 'pdl_custody_status')
            ->with('account') // the officer who made the change
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        // Pull the related PDL for each entry. record_id is a generic
        // polymorphic pointer (audit_logs has no real foreign key to
        // pdl_profiles), so this is a manual lookup rather than an Eloquent
        // relationship — fine at this volume, worth revisiting with eager
        // loading/joins if this page ever needs to handle heavy traffic.
        $pdlIds = $entries->pluck('record_id')->unique();
        $pdls = Pdl::whereIn('pdl_id', $pdlIds)->get()->keyBy('pdl_id');

        $stats = [
            'total_records' => AuditLog::where('record_type', 'pdl_custody_status')->count(),
            'recent_transferred' => AuditLog::where('record_type', 'pdl_custody_status')
                ->where('description', 'like', '%transferred%')
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'total_released' => AuditLog::where('record_type', 'pdl_custody_status')
                ->where('description', 'like', '%released%')
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        return view('custody-history', compact('entries', 'pdls', 'stats'));
    }
}
