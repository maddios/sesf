<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The legacy user, car and sponsor text columns are latin1. A character outside latin1 (an emoji,
 * for example) cannot be stored: strict MariaDB rejects the write, which surfaced as a 500.
 * This rule rejects such input with a friendly message before it reaches the database.
 *
 * Round trip: encode to ISO-8859-1 (unmappable characters become '?') and decode back. Any
 * difference means the value contained a character latin1 cannot hold.
 */
class Latin1Text implements ValidationRule
{
    public const MESSAGE = 'Please use only standard letters (no emoji).';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $latin1 = mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');

        if (mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1') !== $value) {
            $fail(self::MESSAGE);
        }
    }
}
