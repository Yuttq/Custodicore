<?php

namespace App\Http\Controllers;

use App\Services\NavBadges;
use Illuminate\Http\JsonResponse;

/**
 * GET /nav-badges — the Records Officer sidebar badges, rendered, so the
 * sidebar can refresh them every minute without a page reload.
 */
class NavBadgesController extends Controller
{
    public function __invoke(NavBadges $badges): JsonResponse
    {
        return response()->json(collect($badges->all())->map(
            fn ($badge) => $badge ? trim(view('partials.nav-badge', ['badge' => $badge])->render()) : null
        ));
    }
}
