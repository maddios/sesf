<?php

namespace App\Services\Images;

use Closure;
use Imagick;

/**
 * Runs an Imagick operation under process-wide ImageMagick resource limits and restores the previous
 * limits afterwards, so one decode cannot exhaust memory, disk or time.
 */
final class ImagickLimits
{
    /**
     * @template T
     *
     * @param  array<int<0, 11>, int>  $limits  Imagick::RESOURCETYPE_* => limit
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function within(array $limits, Closure $operation): mixed
    {
        $previous = [];
        foreach ($limits as $type => $limit) {
            $previous[$type] = Imagick::getResourceLimit($type);
            Imagick::setResourceLimit($type, $limit);
        }

        try {
            return $operation();
        } finally {
            foreach ($previous as $type => $limit) {
                Imagick::setResourceLimit($type, $limit);
            }
        }
    }
}
