<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pdl;
use App\Support\Sanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Support\Pages;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 1.2 PDL Management — read-only.
 *
 * PDL custody records are created/edited from the Records Officer module
 * (see \App\Http\Controllers\PdlController), not from this admin dashboard.
 * The Warden can search the list and open a PDL to VIEW their profile,
 * legal records and disciplinary records. Nothing here writes anything.
 */
class PdlController extends Controller
{
    public function index(Request $request): View
    {
        $search = Sanitizer::text($request->query('q'));
        $like = $search !== null ? Sanitizer::like($search) : null;

        $pdls = Pdl::query()
            ->withCount(['restrictions as active_restrictions' => fn ($q) => $q->where('status', 'active')])
            ->when($like, function ($query) use ($like) {
                $query->where(function ($q) use ($like) {
                    $q->where('full_name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('alias', 'like', $like)
                        ->orWhere('pdl_number', 'like', $like)
                        ->orWhere('cell_block', 'like', $like);
                });
            })
            ->orderBy('pdl_number')
            ->get()
            ->map(fn ($pdl) => [
                'id' => $pdl->pdl_id,
                'pdl_number' => $pdl->pdl_number,
                'full_name' => $pdl->full_name,
                'classification' => $pdl->classification,
                'cell_block' => $pdl->cell_block,
                'custody_status' => $pdl->custody_status,
                'active_restrictions' => $pdl->active_restrictions,
            ])
            ->all();

        // Summary cards always describe ALL PDLs, not just the search result.
        $total = Pdl::count();
        $activeCustody = Pdl::where('custody_status', 'active')->count();
        $withRestrictions = Pdl::whereHas('restrictions', fn ($q) => $q->where('status', 'active'))->count();

        $summary = [
            ['label' => 'Total PDLs', 'value' => (string) $total, 'icon' => 'identification', 'accent' => 'info'],
            ['label' => 'Active in Custody', 'value' => (string) $activeCustody, 'icon' => 'check-circle', 'accent' => 'success'],
            ['label' => 'With Restrictions', 'value' => (string) $withRestrictions, 'icon' => 'warning', 'accent' => 'danger'],
        ];

        $pdls = Pages::of($pdls);

        return view('admin.pdls.index', [
            'pdls' => $pdls,
            'summary' => $summary,
            'search' => $search,
            'totalPdls' => $total,
        ]);
    }

    /** View one PDL: profile, legal records, disciplinary records. */
    public function show(Pdl $pdl): View
    {
        $pdl->load(['legalRecords', 'disciplinaryRecords', 'visitorRelationships.visitor']);

        // Authorized visitors: people whose relationship to this PDL has been
        // verified by the Records Officer AND whose own account is verified.
        // Listed alphabetically by name.
        $authorizedVisitors = $pdl->visitorRelationships
            ->filter(fn ($rel) => $rel->isVerified()
                && $rel->visitor
                && $rel->visitor->verification_status === 'verified')
            ->sortBy(fn ($rel) => mb_strtolower((string) $rel->visitor->full_name))
            ->values();

        // Linked to this PDL but not (yet) cleared to visit.
        $awaitingVisitors = $pdl->visitorRelationships->count() - $authorizedVisitors->count();

        return view('admin.pdls.show', [
            'pdl' => $pdl,
            'nameParts' => $pdl->nameParts(),
            'authorizedVisitors' => $authorizedVisitors,
            'awaitingVisitors' => $awaitingVisitors,
        ]);
    }

    /** The PDL's photo (stored privately, so it has to be streamed through a route). */
    public function photo(Pdl $pdl): Response
    {
        abort_unless($pdl->photo_path && Storage::disk('local')->exists($pdl->photo_path), 404);

        return Storage::disk('local')->response($pdl->photo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
