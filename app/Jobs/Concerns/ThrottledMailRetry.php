<?php

namespace App\Jobs\Concerns;

use App\Jobs\Waivers\ThrottleWaiverMail;
use DateTimeInterface;

/**
 * Retry policy for throttled admin mail jobs: a release by ThrottleWaiverMail does not use up the retry budget
 * (tries = 0, bounded by retryUntil); three real failures end in failed_jobs.
 */
trait ThrottledMailRetry
{
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleWaiverMail];
    }
}
