<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisitorProfile;
use Illuminate\View\View;

/**
 * Module 1.3 Visitor Management — read-only.
 *
 * Visitors register and get verified through the mobile app (and the
 * Records Officer module's verification actions); this admin dashboard
 * only displays their real visitor_profiles rows.
 */
class VisitorController extends Controller
{
    public function index(): View
    {
        $visitors = self::rows();

        $verified = count(array_filter($visitors, fn ($v) => $v['verification_status'] === 'verified'));
        $pending = count(array_filter($visitors, fn ($v) => $v['verification_status'] === 'pending'));

        $summary = [
            ['label' => 'Total Visitors', 'value' => (string) count($visitors), 'icon' => 'visitor', 'accent' => 'info'],
            ['label' => 'Verified', 'value' => (string) $verified, 'icon' => 'check-circle', 'accent' => 'success'],
            ['label' => 'Pending Verification', 'value' => (string) $pending, 'icon' => 'clock', 'accent' => 'warning'],
        ];

        return view('admin.visitors.index', compact('visitors', 'summary'));
    }

    /**
     * Names of verified visitors — kept as a static helper since
     * Admin\CheckinController's view historically referenced it, though
     * check-in/out is now fully read-only and driven by real gate scans.
     */
    public static function verifiedNames(): array
    {
        return VisitorProfile::where('verification_status', 'verified')->pluck('full_name')->all();
    }

    private static function rows(): array
    {
        return VisitorProfile::query()
            ->with(['relationships' => fn ($q) => $q->orderByDesc('priority_tier')->with('pdl')])
            ->orderBy('full_name')
            ->get()
            ->map(function ($visitor) {
                $relationship = $visitor->relationships->first();

                return [
                    'full_name' => $visitor->full_name,
                    'contact_number' => $visitor->contact_number,
                    'related_pdl_name' => $relationship?->pdl?->full_name ?? '—',
                    'related_pdl_number' => $relationship?->pdl?->pdl_number ?? '—',
                    'relationship_type' => $relationship?->relationship_type ?? 'unknown_or_other',
                    'verification_status' => $visitor->verification_status,
                ];
            })
            ->all();
    }
}
