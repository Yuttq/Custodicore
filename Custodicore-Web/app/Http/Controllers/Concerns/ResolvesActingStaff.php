<?php

namespace App\Http\Controllers\Concerns;

/**
 * Staff attribution for actions that record who did them (verified_by,
 * reviewed_by, recorded_by, check_in_officer_id, ...). Always the signed-in
 * account's own staff profile — never another officer's, e.g. the first
 * staff_profiles row. Callers refuse the action when this is null.
 */
trait ResolvesActingStaff
{
    protected const NO_STAFF_PROFILE_MESSAGE = 'Your account has no staff profile, so this action cannot be recorded. Ask an administrator to complete your staff profile.';

    protected function actingStaffId(): ?int
    {
        $staffId = auth()->user()?->staffProfile?->staff_id;

        return $staffId === null ? null : (int) $staffId;
    }
}
