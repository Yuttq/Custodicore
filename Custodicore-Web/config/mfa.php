<?php

/*
|--------------------------------------------------------------------------
| Staff two-step verification (web dashboards only)
|--------------------------------------------------------------------------
|
| After the email/username and password, every staff member (Admin/Warden,
| Record Officer, Front Desk Officer) enters a 6-digit code from an
| authenticator app. An emailed code or a one-time backup code also works.
| Visitors on the mobile app are NOT affected. See App\Services\Auth\StaffMfaService.
|
*/

return [
    // true  = every staff account must set up and use a code (new and existing accounts are
    //         walked through setup at their next sign-in).
    // false = only accounts that already turned it on are asked (handy while developing).
    'required' => (bool) env('STAFF_MFA_REQUIRED', true),

    // Shown inside the authenticator app next to the account name.
    'issuer' => env('STAFF_MFA_ISSUER', 'CustodiCore'),

    // Minutes between entering the password and finishing the code step.
    'pending_minutes' => (int) env('STAFF_MFA_PENDING_MINUTES', 10),

    // Wrong codes allowed per officer + IP before they must start over (lock lasts decay_seconds).
    'max_attempts' => (int) env('STAFF_MFA_MAX_ATTEMPTS', 5),
    'decay_seconds' => (int) env('STAFF_MFA_DECAY_SECONDS', 900),

    // "Trust this device" checkbox: days the browser skips the code. 0 turns the option off.
    'trusted_days' => (int) env('STAFF_MFA_TRUSTED_DAYS', 30),

    // Emailed code: how long it works and how many may be requested per 15 minutes.
    'email_code_minutes' => (int) env('STAFF_MFA_EMAIL_CODE_MINUTES', 10),
    'email_max_sends' => (int) env('STAFF_MFA_EMAIL_MAX_SENDS', 3),

    // One-time backup codes handed out at setup.
    'backup_codes' => 8,
];
