<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** Module 1.3 Visitor Management (mobile-based). */
class VisitorController extends Controller
{
    public function index(): View
    {
        $visitors = [
            ['full_name' => 'Maria D. Santos', 'contact_number' => '0917 123 4567', 'related_pdl' => 'Ramon G. Bautista (PDL-0032)', 'relationship_type' => 'immediate_family', 'verification_status' => 'verified'],
            ['full_name' => 'Carlo J. Ramos', 'contact_number' => '0928 555 0199', 'related_pdl' => 'Ellen M. Cruz (PDL-0014)', 'relationship_type' => 'legal_counsel', 'verification_status' => 'verified'],
            ['full_name' => 'Liza P. Aquino', 'contact_number' => '0906 442 8871', 'related_pdl' => 'Jerome S. Villareal (PDL-0055)', 'relationship_type' => 'approved_relative', 'verification_status' => 'pending'],
            ['full_name' => 'Dante R. Cabrera', 'contact_number' => '0933 210 7745', 'related_pdl' => 'Vicente A. Torres (PDL-0091)', 'relationship_type' => 'unknown_or_other', 'verification_status' => 'pending'],
            ['full_name' => 'Fe M. Lopez', 'contact_number' => '0917 887 3302', 'related_pdl' => 'Bea L. Santiago (PDL-0102)', 'relationship_type' => 'immediate_family', 'verification_status' => 'rejected'],
        ];

        return view('admin.visitors.index', compact('visitors'));
    }
}
