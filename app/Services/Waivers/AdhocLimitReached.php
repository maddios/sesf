<?php

namespace App\Services\Waivers;

use RuntimeException;

/** This IP has started too many walk-in waivers in the last hour. */
final class AdhocLimitReached extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many waivers were started from this network. Please wait and try again.');
    }
}
