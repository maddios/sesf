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

        $dollars = (int) $m[1];
        $fraction = str_pad($m[2] ?? '', 3, '0');

        $cents = $dollars * 100 + (int) substr($fraction, 0, 2);

        if ($fraction[2] >= '5') {
            $cents++;
        }

        return $cents;
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
