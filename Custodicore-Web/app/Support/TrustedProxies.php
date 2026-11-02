<?php

namespace App\Support;

/**
 * Reads TRUSTED_PROXIES: which machines in front of the app may tell it the
 * visitor's real IP address (X-Forwarded-For) and that the visit used HTTPS
 * (X-Forwarded-Proto). Used by config/trustedproxy.php, which Laravel's
 * TrustProxies middleware reads on every request.
 *
 * Accepted, comma-separated:
 *   REMOTE_ADDR    the machine connecting directly to the app (Azure App
 *                  Service's front end, whose addresses are not fixed). "*"
 *                  means the same and is turned into REMOTE_ADDR.
 *   IP or CIDR     e.g. 10.0.0.0/8, 2a01:111:2050::/44 (Azure Front Door's
 *                  published ranges).
 *
 * Anything else is dropped, so a typo trusts less, never more. That includes
 * "**" and very wide ranges (IPv4 shorter than /8, IPv6 shorter than /16, such
 * as 0.0.0.0/0), which would let anyone on the internet fake their address.
 * Empty or nothing valid means no proxy is trusted: forwarded headers are
 * ignored and the connecting address is used as is.
 */
class TrustedProxies
{
    public const CALLING_PROXY = 'REMOTE_ADDR';

    private const MIN_PREFIX_V4 = 8;
    private const MIN_PREFIX_V6 = 16;

    /** @return list<string>|null */
    public static function parse(?string $value): ?array
    {
        $trusted = [];

        foreach (explode(',', (string) $value) as $entry) {
            $entry = trim($entry);

            if ($entry === self::CALLING_PROXY || $entry === '*') {
                $trusted[] = self::CALLING_PROXY;
            } elseif (self::isAddressOrRange($entry)) {
                $trusted[] = $entry;
            }
        }

        return $trusted === [] ? null : array_values(array_unique($trusted));
    }

    private static function isAddressOrRange(string $entry): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $v6 = str_contains($ip, ':');

        return ctype_digit($prefix)
            && (int) $prefix >= ($v6 ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4)
            && (int) $prefix <= ($v6 ? 128 : 32);
    }
}
