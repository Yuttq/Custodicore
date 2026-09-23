<?php

namespace App\Support;

/**
 * Session-backed audit trail shared by every admin module.
 *
 * There is no database yet, so this simply keeps a running list of entries
 * in the browser session: every action a controller performs (creating a
 * PDL, checking a visitor in, approving a visit request, ...) calls
 * AuditLog::record() so it shows up on the Audit Trail page. Entries reset
 * whenever the session resets or the app server restarts.
 */
class AuditLog
{
    private const SESSION_KEY = 'audit_logs';

    /**
     * Append a new entry to the top of the log.
     */
    public static function record(string $actionType, string $module, string $description, ?string $account = null): void
    {
        $logs = self::all();

        array_unshift($logs, [
            'account' => $account ?? 'a.domingo@bjmp.gov.ph',
            'action_type' => $actionType,
            'module' => $module,
            'description' => $description,
            'created_at' => now()->format('Y-m-d H:i'),
        ]);

        session([self::SESSION_KEY => $logs]);
    }

    /**
     * All logged entries, seeding the session with $seed the first time
     * this is called so the page isn't empty before anything has happened.
     */
    public static function all(array $seed = []): array
    {
        if (! session()->has(self::SESSION_KEY)) {
            session([self::SESSION_KEY => $seed]);
        }

        return session(self::SESSION_KEY, $seed);
    }
}
