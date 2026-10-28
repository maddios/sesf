<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    /**
     * Convert a decimal amount in dollars to integer cents.
     *
     * Strings are parsed exactly (no float drift). Floats are rendered to
     * eight decimal places first. Halves round up (0.005 becomes 1 cent).
     *
     * @throws InvalidArgumentException for negative, non-finite or non-numeric input
     */
    public static function toCents(string|float $amount): int
    {
        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('Amount must be a finite number.');
            }
            if ($amount < 0) {
                throw new InvalidArgumentException('Amount must not be negative.');
            }
            // Reject before formatting so huge floats never reach the integer math.
            if ($amount > PHP_INT_MAX / 100) {
                throw new InvalidArgumentException('Amount is too large.');
            }
            // Normalise -0.0 so it does not render as "-0.00000000".
            $amount = $amount == 0.0 ? 0.0 : $amount;
            $amount = sprintf('%.8F', $amount);
        }

        $amount = trim($amount);

        if (str_starts_with($amount, '-')) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        if (preg_match('/^(\d+)(?:\.(\d*))?$/', $amount, $m) !== 1) {
            throw new InvalidArgumentException("Invalid amount: \"{$amount}\".");
        }

        // Digits-only check before casting: (int) saturates or overflows on long strings.
        $wholeDigits = ltrim($m[1], '0');
        if (strlen($wholeDigits) > 18) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        $dollars = (int) $wholeDigits;
        if ($dollars > intdiv(PHP_INT_MAX, 100)) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        $fraction = str_pad($m[2] ?? '', 3, '0');

        $base = $dollars * 100;
        $extra = (int) substr($fraction, 0, 2) + ($fraction[2] >= '5' ? 1 : 0);

        if ($extra > PHP_INT_MAX - $base) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        return $base + $extra;
    }

    /**
     * Like toCents, but a leading minus sign is kept (stored ledger values such as a refund or a
     * legacy negative discount).
     */
    public static function toSignedCents(string|float $amount): int
    {
        if (is_float($amount)) {
            return $amount < 0 ? -self::toCents(-$amount) : self::toCents($amount);
        }

        $amount = trim($amount);

        return str_starts_with($amount, '-') ? -self::toCents(substr($amount, 1)) : self::toCents($amount);
    }

    /**
     * Format integer cents as a US dollar string, e.g. 123450 becomes "$1,234.50".
     *
     * @throws InvalidArgumentException for negative cents
     */
    public static function format(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Cents must not be negative.');
        }

        return '$'.number_format(intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
