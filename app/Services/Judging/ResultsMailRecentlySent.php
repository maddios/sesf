<?php

namespace App\Services\Judging;

use RuntimeException;

final class ResultsMailRecentlySent extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The results emails for this event were just sent. Wait a minute before sending them again.');
    }
}
