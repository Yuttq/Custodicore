<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route (or route group) to one or more staff roles, e.g.
 *   ->middleware('role:System Administrator/Warden')
 *   ->middleware('role:Record Officer,Front Desk Officer')
 *
 * Must run after the built-in 'auth' middleware — it assumes
 * $request->user() is already the logged-in Account. Registered as the
 * 'role' alias in bootstrap/app.php.
 */
class EnsureRoleAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $account = $request->user();

        if (! $account || ! in_array($account->role?->role_name, $roles, true)) {
            abort(403, 'Your account does not have access to this dashboard.');
        }

        return $next($request);
    }
}
