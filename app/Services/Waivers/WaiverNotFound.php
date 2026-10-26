<?php

namespace App\Services\Waivers;

use RuntimeException;

/** No waiver (or no event, registration or guest behind it) for the given link. */
final class WaiverNotFound extends RuntimeException
{
    public function __construct(string $message = 'Invalid waiver link.')
    {
        parent::__construct($message);
    }
}
