<?php

namespace App\Models\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cast for legacy float(6,2) money columns. Rounding is done on decimal strings
 * (half up at the third decimal, via App\Support\Money), never on binary floats.
 *
 * get(): stored value to a string with exactly 2 decimals, e.g. "12.30". Null stays null.
 * set(): int, float or numeric string to a float with 2 decimals, e.g. "12.345" to 12.35.
 *        Null and blank strings store null. Non-numeric input throws InvalidArgumentException.
 *
 * Optional max argument (cast string "Money2:9999.99"): set() rejects amounts whose absolute value
 * is larger than max, with InvalidArgumentException. Use the DDL limit of the column:
 * float(6,2) is 9999.99, float(8,2) is 999999.99, double(10,2) is 99999999.99.
 * Without a max, any finite amount is accepted (the database then decides). Negative amounts are
 * allowed either way. Non-strict MySQL clamps out-of-range floats silently, so the max is the guard.
 *
 * @implements CastsAttributes<?string, float|int|string|null>
 */
final class Money2 implements CastsAttributes
{
    /** Largest accepted absolute amount in cents, or null for no limit. */
    private readonly ?int $maxCents;

    public function __construct(?string $max = null)
    {
        $this->maxCents = $max === null || trim($max) === '' ? null : Money::toCents(trim($max));
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $cents = self::toCents($value);
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $cents = self::toCents($value);

        if ($this->maxCents !== null && abs($cents) > $this->maxCents) {
            throw new InvalidArgumentException('Money value is larger than the column allows.');
        }

        return $cents / 100;
    }

    /**
     * @return int Signed amount in cents, rounded half up.
     *
     * @throws InvalidArgumentException for non-numeric, non-finite or out-of-range input.
     */
    private static function toCents(mixed $value): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Money value must be an int, float or numeric string.');
        }

        if (is_string($value) && preg_match('/^\s*-?\d+(\.\d*)?\s*$/', $value) === 1) {
            $text = trim($value);
        } else {
            if (! is_numeric($value) || (is_float($value) && ! is_finite($value))) {
                throw new InvalidArgumentException('Money value must be numeric and finite.');
            }
            // Exponent notation and ints are rendered as plain decimals before rounding.
            $text = sprintf('%.8F', (float) $value);
        }

        $negative = str_starts_with($text, '-');
        $cents = Money::toCents(ltrim($text, '-'));

        return $negative ? -$cents : $cents;
    }
}
