<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs work once the response has been sent (when the kernel terminates), so slow work such as a
 * database write and a mail send does not change how long the response takes.
 *
 * Each call registers its own terminating callback that runs its work at most once. A plain
 * terminating callback would run again on every later terminate in the same process, which
 * happens in tests and in long-running workers.
 */
final class AfterResponse
{
    public static function run(Closure $work): void
    {
        $pending = true;

        app()->terminating(function () use (&$pending, $work): void {
            if (! $pending) {
                return;
            }

            $pending = false;

            try {
                $work();
            } catch (Throwable $e) {
                Log::error('Deferred work failed: '.$e->getMessage(), ['exception' => $e]);
            }
        });
    }
}
