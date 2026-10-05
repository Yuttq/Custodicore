<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * The Google ID token failed verification. The message is a short reason
 * code (e.g. "audience", "email_unverified") for tests/diagnostics — it
 * never contains the token itself. Do not show it to the client.
 */
class InvalidGoogleIdTokenException extends RuntimeException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
