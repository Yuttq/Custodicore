<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Thrown inside the password broker's reset callback when the account is no
 * longer eligible (see PasswordResetService::isEligible()), so the password
 * is left unchanged. Reported to the visitor as an invalid link.
 */
class PasswordResetRefusedException extends RuntimeException
{
}
