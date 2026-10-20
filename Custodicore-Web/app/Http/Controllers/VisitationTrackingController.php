<?php

namespace App\Http\Controllers;

use App\Models\Pdl;
use App\Models\VisitRequest;

class VisitationTrackingController extends Controller
{
    // -----------------------------------------------------------------
    // LIST — PDLs flagged by how recently (or whether) they've had a
    // confirmed visit.
    // -----------------------------------------------------------------
    public function show(Pdl $pdl)
    {
        $relationships = $pdl->visitorRelationships()->with('visitor')->get();

        $visitRequests = VisitRequest::where('pdl_id', $pdl->pdl_id)
            ->with(['visitor', 'relationship', 'eligibilityAssessment'])
            ->orderBy('assigned_at', 'desc')
            ->get();

        return view('pdl.visit-history', compact('pdl', 'relationships', 'visitRequests'));
    }
}