<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pdl;
use Illuminate\View\View;

/**
 * Module 1.2 PDL Management — read-only.
 *
 * PDL custody records are created/edited from the Records Officer module
 * (see \App\Http\Controllers\PdlController), not from this admin dashboard
 * — this just displays the same real pdl_profiles rows.
 */
class PdlController extends Controller
{
    public function index(): View
    {
        $pdls = Pdl::query()
            ->withCount(['restrictions as active_restrictions' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('pdl_number')
            ->get()
            ->map(fn ($pdl) => [
                'pdl_number' => $pdl->pdl_number,
                'full_name' => $pdl->full_name,
                'classification' => $pdl->classification,
                'cell_block' => $pdl->cell_block,
                'custody_status' => $pdl->custody_status,
                'active_restrictions' => $pdl->active_restrictions,
            ])
            ->all();

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
