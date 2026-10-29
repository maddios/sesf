<?php

namespace App\Jobs\Waivers;

use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Queue middleware for admin-triggered waiver mail: at most PER_MINUTE emails a minute across all
 * workers. A job over the budget is released back to the queue for the time left in the window.
 */
final class ThrottleWaiverMail
{
    public const PER_MINUTE = 20;

    private const KEY = 'admin-waiver-mail';

    public function handle(object $job, Closure $next): void
    {
        if (RateLimiter::attempt(self::KEY, self::PER_MINUTE, fn (): bool => true, 60)) {
            $next($job);

            return;
        }

        $job->release(max(1, RateLimiter::availableIn(self::KEY)));
    }
}
