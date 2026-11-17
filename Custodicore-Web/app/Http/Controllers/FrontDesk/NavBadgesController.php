<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Services\FrontDeskNavBadges;
use Illuminate\Http\JsonResponse;

/**
 * GET front-desk/nav-badges — the Front Desk sidebar badges, rendered, so the
 * sidebar can refresh them without a page reload (see layouts/frontdesk).
 */
class NavBadgesController extends Controller
{
    public function __invoke(FrontDeskNavBadges $badges): JsonResponse
    {
        return response()->json(collect($badges->all())->map(
            fn ($badge) => $badge ? trim(view('admin.partials.nav-badge', ['badge' => $badge])->render()) : null
        ));
    }
}
