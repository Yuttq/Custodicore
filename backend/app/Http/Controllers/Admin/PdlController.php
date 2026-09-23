<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Module 1.2 PDL Management — read-only.
 *
 * PDL custody records are not created or edited from this admin
 * dashboard, so there is nothing here to register or modify — just the
 * current sample records for reference.
 */
class PdlController extends Controller
{
    public function index(): View
    {
        $pdls = [
            ['pdl_number' => 'PDL-0032', 'full_name' => 'Ramon G. Bautista', 'classification' => 'non_drug_related', 'cell_block' => 'Dorm 3', 'custody_status' => 'active', 'active_restrictions' => 0],
            ['pdl_number' => 'PDL-0014', 'full_name' => 'Ellen M. Cruz', 'classification' => 'drug_related', 'cell_block' => 'Dorm 1', 'custody_status' => 'active', 'active_restrictions' => 1],
            ['pdl_number' => 'PDL-0055', 'full_name' => 'Jerome S. Villareal', 'classification' => 'drug_related', 'cell_block' => 'Dorm 2', 'custody_status' => 'active', 'active_restrictions' => 0],
            ['pdl_number' => 'PDL-0091', 'full_name' => 'Vicente A. Torres', 'classification' => 'non_drug_related', 'cell_block' => 'Dorm 4', 'custody_status' => 'transferred', 'active_restrictions' => 0],
            ['pdl_number' => 'PDL-0102', 'full_name' => 'Bea L. Santiago', 'classification' => 'non_drug_related', 'cell_block' => 'Dorm 5 (F)', 'custody_status' => 'active', 'active_restrictions' => 2],
        ];

        $activeCustody = count(array_filter($pdls, fn ($p) => $p['custody_status'] === 'active'));
        $withRestrictions = count(array_filter($pdls, fn ($p) => $p['active_restrictions'] > 0));

        $summary = [
            ['label' => 'Total PDLs', 'value' => (string) count($pdls), 'icon' => 'identification', 'accent' => 'info'],
            ['label' => 'Active in Custody', 'value' => (string) $activeCustody, 'icon' => 'check-circle', 'accent' => 'success'],
            ['label' => 'With Restrictions', 'value' => (string) $withRestrictions, 'icon' => 'warning', 'accent' => 'danger'],
        ];

        return view('admin.pdls.index', compact('pdls', 'summary'));
    }
}
