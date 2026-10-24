<?php

namespace App\Enums\Concerns;

/**
 * Legacy MySQL columns come back as strings (e.g. '1'), so accept numeric
 * strings as well as ints and return null for anything unknown.
 */
trait TriesLegacyValues
{
    public static function tryFromLegacy(int|string|null $value): ?self
    {
        $id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : $value;

        if (! is_int($id)) {
            return null;
        }

        return self::tryFrom($id);
    }
}
