<?php

namespace App\Services\SupportAccess;

use RuntimeException;

/**
 * A support pass that must not open a session. `reason` is a fixed code for the log (never the pass or a claim
 * value); the visitor always gets the same generic 404.
 */
final class InvalidSupportPass extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid support pass.');
    }
}
