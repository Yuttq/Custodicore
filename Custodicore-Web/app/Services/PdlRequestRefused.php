<?php

namespace App\Services;

/**
 * A new request to add a PDL was refused before anything was stored. The
 * code tells the app which case it is; the message is shown to the visitor.
 */
class PdlRequestRefused extends \RuntimeException
{
    /** The visitor already has PdlLinkRequestService::MAX_OPEN open requests. */
    public const LIMIT = 'pdl_request_limit';

    /** An open request for a PDL with the same name already exists. */
    public const DUPLICATE = 'duplicate_pdl_request';

    /** The visitor is already linked (pending or approved) to a PDL with that name. */
    public const ALREADY_LINKED = 'pdl_already_linked';

    public function __construct(public readonly string $refusal, string $message)
    {
        parent::__construct($message);
    }

    public static function limit(int $max): self
    {
        return new self(self::LIMIT,
            "You already have {$max} PDL requests waiting for facility staff. Wait for one to be reviewed, or cancel one in My PDLs, before sending another.");
    }

    public static function duplicate(string $pdlName): self
    {
        return new self(self::DUPLICATE,
            "You already have a request to add {$pdlName} waiting for facility staff. You can see it in My PDLs.");
    }

    public static function alreadyLinked(string $pdlName): self
    {
        return new self(self::ALREADY_LINKED,
            "{$pdlName} is already on your account. You can see this PDL in My PDLs.");
    }
}
