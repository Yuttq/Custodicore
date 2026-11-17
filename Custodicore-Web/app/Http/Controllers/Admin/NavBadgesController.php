<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminNavBadges;
use Illuminate\Http\JsonResponse;

/**
 * GET admin/nav-badges — the Admin sidebar badges, rendered, so the sidebar
 * can refresh them without a page reload (see layouts/admin).
 */
class NavBadgesController extends Controller
{
    public function __invoke(AdminNavBadges $badges): JsonResponse
    {
        return response()->json(collect($badges->all())->map(
            fn ($badge) => $badge ? trim(view('admin.partials.nav-badge', ['badge' => $badge])->render()) : null
        ));
    }
}
