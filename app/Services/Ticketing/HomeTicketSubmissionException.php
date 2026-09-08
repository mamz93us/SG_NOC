<?php

namespace App\Services\Ticketing;

use RuntimeException;

/** A user-facing ticket submission failure, with the HTTP status the caller should answer with. */
class HomeTicketSubmissionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
