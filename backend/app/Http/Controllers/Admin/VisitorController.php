<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Module 1.3 Visitor Management — read-only.
 *
 * Visitors register and get verified through the mobile app; this admin
 * dashboard only displays their profiles and verification status, it
 * doesn't create or change them.
 */
class VisitorController extends Controller
{
    public function index(): View
    {
        $visitors = self::sampleRows();

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
     * Names of verified visitors, used by Check-In / Check-Out to suggest
     * who is likely to be checking in.
     */
    public static function verifiedNames(): array
    {
        return array_values(array_map(
            fn ($v) => $v['full_name'],
            array_filter(self::sampleRows(), fn ($v) => $v['verification_status'] === 'verified')
        ));
    }

    private static function sampleRows(): array
    {
        return [
            ['full_name' => 'Maria D. Santos', 'contact_number' => '0917 123 4567', 'related_pdl_name' => 'Ramon G. Bautista', 'related_pdl_number' => 'PDL-0032', 'relationship_type' => 'immediate_family', 'verification_status' => 'verified'],
            ['full_name' => 'Carlo J. Ramos', 'contact_number' => '0928 555 0199', 'related_pdl_name' => 'Ellen M. Cruz', 'related_pdl_number' => 'PDL-0014', 'relationship_type' => 'legal_counsel', 'verification_status' => 'verified'],
            ['full_name' => 'Liza P. Aquino', 'contact_number' => '0906 442 8871', 'related_pdl_name' => 'Jerome S. Villareal', 'related_pdl_number' => 'PDL-0055', 'relationship_type' => 'approved_relative', 'verification_status' => 'pending'],
            ['full_name' => 'Dante R. Cabrera', 'contact_number' => '0933 210 7745', 'related_pdl_name' => 'Vicente A. Torres', 'related_pdl_number' => 'PDL-0091', 'relationship_type' => 'unknown_or_other', 'verification_status' => 'pending'],
            ['full_name' => 'Fe M. Lopez', 'contact_number' => '0917 887 3302', 'related_pdl_name' => 'Bea L. Santiago', 'related_pdl_number' => 'PDL-0102', 'relationship_type' => 'immediate_family', 'verification_status' => 'rejected'],
        ];
    }
}
