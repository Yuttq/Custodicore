<?php

namespace App\Services;

/** The visitor already has an open request to add another PDL (one at a time). */
class OpenPdlRequestExists extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('You already have a PDL request waiting for facility staff. Cancel it or wait for staff to review it before sending another.');
    }
}
