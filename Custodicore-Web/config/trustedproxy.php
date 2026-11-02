<?php

use App\Support\TrustedProxies;

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
| Behind Azure the app never sees the visitor directly, so the real IP and
| "this was HTTPS" arrive in X-Forwarded-For / X-Forwarded-Proto. Those are
| believed only when they come from a machine listed in TRUSTED_PROXIES (see
| App\Support\TrustedProxies for the accepted values). Unset = nobody is
| trusted, which is right for local development.
|
| Which headers are believed is set in bootstrap/app.php (trustProxies):
| X-Forwarded-For, -Proto and -Port only, never -Host or -Prefix.
*/

return [
    'proxies' => TrustedProxies::parse(env('TRUSTED_PROXIES')),
];
