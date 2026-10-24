<?php

namespace App\Support;

/**
 * Port of legacy StringHelper::numeric_path(): the sharded directory for a gallery item id.
 *
 * The id is left-padded with zeros to at least 3 characters, then the first three characters
 * are joined with '/'. Only three levels are produced, so 12345 becomes "1/2/3" (not "1/2/3/4/5").
 * Non-numeric input is not rejected, and it is handled exactly as the legacy code handled it
 * (for example '-5' becomes "0/-/5").
 */
final class NumericPath
{
    public static function for(int|string $id): string
    {
        $padded = str_pad((string) $id, 3, '0', STR_PAD_LEFT);

        return implode('/', array_slice(str_split($padded), 0, 3));
    }
}
