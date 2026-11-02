<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every Laravel response (web pages, API, files).
 *
 * The CSP only blocks framing, <base>, off-site form posts and plugins. It
 * does not restrict scripts or styles: the views still use inline scripts,
 * handlers and styles. The camera stays allowed for the front desk QR scanner.
 * A header a response already set itself is left as it is.
 *
 * HSTS is sent only over HTTPS (behind Azure that needs TRUSTED_PROXIES) and
 * only when HSTS_MAX_AGE is above 0; never with includeSubDomains or preload.
 */
class SecurityHeaders
{
    private const HEADERS = [
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $maxAge = (int) config('app.hsts_max_age');

        if ($maxAge > 0 && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=' . $maxAge);
        }

        return $response;
    }
}
