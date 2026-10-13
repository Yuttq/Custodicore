<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Laravel's StartSession, except that pages whose URL carries a secret are
 * never remembered as the session's "previous URL". Laravel stores the full
 * URL of every GET page there, after the controller has run; with the
 * database session driver that would keep a plaintext password reset token
 * in the sessions table.
 *
 * Replaces the stock middleware in the 'web' group (bootstrap/app.php) and
 * is bound in AppServiceProvider with the same constructor arguments as the
 * stock singleton. Everything else is unchanged.
 */
class StartSession extends BaseStartSession
{
    /** Route names whose URL contains a secret token. */
    public const SECRET_URL_ROUTES = ['password.reset'];

    protected function storeCurrentUrl(Request $request, $session)
    {
        if (in_array($request->route()?->getName(), self::SECRET_URL_ROUTES, true)) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
