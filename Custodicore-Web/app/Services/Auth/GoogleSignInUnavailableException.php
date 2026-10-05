<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Google Sign-In cannot be used right now: no client IDs are configured, or
 * Google's signing keys could not be fetched. Not the client's fault.
 */
class GoogleSignInUnavailableException extends RuntimeException
{
}
