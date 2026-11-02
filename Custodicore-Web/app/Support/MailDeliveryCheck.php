<?php

namespace App\Support;

use App\Services\Auth\StaffInvitationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Production with MAIL_MAILER=log or array delivers no email at all: email
 * verification, password reset, staff setup links and two-step codes are
 * silently dropped. This writes one critical log line (at most once an hour)
 * so the misconfiguration is noticed. It never stops the app from starting.
 */
class MailDeliveryCheck
{
    public const CACHE_KEY = 'mail-delivery-check:warned';

    public static function warnIfNotDelivering(bool $isProduction): void
    {
        if (! $isProduction || ! StaffInvitationService::mailIsLogOnly()) {
            return;
        }

        try {
            if (Cache::add(self::CACHE_KEY, true, now()->addHour())) {
                Log::critical('MAIL_MAILER is "' . config('mail.default') . '" in production, so no email is delivered '
                    . '(email verification, password reset, staff setup links, two-step codes). '
                    . 'Set MAIL_MAILER=smtp; see README "Azure Communication Services Email".');
            }
        } catch (\Throwable) {
            // A warning must never break startup (e.g. the cache database is not reachable yet).
        }
    }
}
