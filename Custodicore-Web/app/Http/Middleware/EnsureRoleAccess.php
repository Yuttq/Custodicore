<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route (or route group) to one or more staff roles, e.g.
 *   ->middleware('role:System Administrator/Warden')
 *   ->middleware('role:Record Officer,Front Desk Officer')
 *
 * Must run after the built-in 'auth' middleware — it assumes
 * $request->user() is already the logged-in Account. Registered as the
 * 'role' alias in bootstrap/app.php.
 *
 * Also signs out an officer whose account is no longer active (deactivated
 * or suspended after they logged in): the status is read from the database
 * on every request, so an open browser session stops working at once.
 */
class EnsureRoleAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $account = $request->user();

        if ($account && Account::whereKey($account->getKey())->value('status') !== 'active') {
            return $this->signOut($request);
        }

        if (! $account || ! in_array($account->role?->role_name, $roles, true)) {
            abort(403, 'Your account does not have access to this dashboard.');
        }

        return $next($request);
    }

    private function signOut(Request $request): Response
    {
        $message = 'Your account is not active. Contact the Warden to restore access.';

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['code' => 'account_inactive', 'message' => $message], 401);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
