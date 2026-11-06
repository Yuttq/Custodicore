<?php

namespace App\Services;

/** The request was already linked, rejected or cancelled, so it can no longer be changed. */
class PdlRequestNotOpen extends \RuntimeException
{
    public function __construct(public readonly ?string $status = null)
    {
        parent::__construct(match ($status) {
            'linked' => 'Facility staff already linked this PDL request.',
            'rejected' => 'Facility staff already reviewed this PDL request.',
            'cancelled' => 'This PDL request was already cancelled.',
            default => 'This PDL request can no longer be changed.',
        });
    }
}
