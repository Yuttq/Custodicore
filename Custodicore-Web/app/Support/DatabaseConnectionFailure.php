<?php

namespace App\Support;

use PDOException;
use Throwable;

/**
 * Tells a failure to reach the database apart from an ordinary query error.
 *
 * Used by bootstrap/app.php so that, on api/* requests, a database that is
 * stopped, unreachable or refusing connections answers 503
 * `service_unavailable` (the mobile app shows "Service temporarily
 * unavailable") instead of a generic 500. Everything else — a missing table,
 * a constraint violation, a syntax error — is left to the normal handler.
 *
 * Recognised, anywhere in the exception chain (QueryException wraps the
 * driver's PDOException):
 *   - SQLSTATE class 08 "connection exception" (e.g. 08S01 communication
 *     link failure, 08004 too many connections).
 *   - MySQL client errors raised while connecting or when the link drops:
 *     2002 can't connect / refused / timed out, 2003 can't connect to host,
 *     2005 unknown host, 2006 server has gone away, 2013 lost connection
 *     during query, 2055 lost connection (system error).
 *
 * Not recognised on purpose: 1045 access denied and other server-side errors,
 * which mean a misconfiguration rather than a temporary outage.
 */
class DatabaseConnectionFailure
{
    /** MySQL client error numbers that mean the server could not be reached. */
    private const MYSQL_CONNECTION_ERRORS = [2002, 2003, 2005, 2006, 2013, 2055];

    /** Exception chains are short; this only guards against a cycle. */
    private const MAX_DEPTH = 10;

    public static function matches(Throwable $e): bool
    {
        for ($depth = 0; $e !== null && $depth < self::MAX_DEPTH; $depth++, $e = $e->getPrevious()) {
            if ($e instanceof PDOException && self::isConnectionError($e)) {
                return true;
            }
        }

        return false;
    }

    private static function isConnectionError(PDOException $e): bool
    {
        // Errors on an open connection carry errorInfo [SQLSTATE, driver code, text].
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        // Errors while connecting have no errorInfo; the message reads
        // "SQLSTATE[HY000] [2002] Connection refused".
        if (preg_match('/SQLSTATE\[(\w+)\](?:\s*\[(\d+)\])?/', $e->getMessage(), $m)) {
            $sqlState ??= $m[1];
            $driverCode ??= isset($m[2]) ? (int) $m[2] : null;
        }

        if (is_string($sqlState) && str_starts_with($sqlState, '08')) {
            return true;
        }

        return in_array((int) $driverCode, self::MYSQL_CONNECTION_ERRORS, true);
    }
}
