<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonical form of an email address for rate-limit keys and user lookups: trimmed, lower-case and
 * ASCII-folded. MariaDB's accent-insensitive collation already matches 'vïctim@x' to 'victim@x' in
 * the database, so folding here only stops one person from getting a fresh limiter bucket per spelling.
 */
final class EmailAddress
{
    public static function normalize(mixed $raw): string
    {
        return Str::ascii(mb_strtolower(trim(is_string($raw) ? $raw : '')));
    }
}
