<?php

namespace App\Services\Waivers;

use RuntimeException;

/** The waiver is already signed. A signed waiver is never altered or signed again. */
final class WaiverAlreadySigned extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This waiver is already on file—you do not need to sign again. If a status page still shows "not signed", refresh it or reopen the waiver portal.');
    }
}
