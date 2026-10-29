<?php

namespace App\Services\PayPal;

use App\Models\ShowRegistration;

/**
 * Fallback RegistrationBalance: the stored amount_owed (as loaded from the database) minus
 * whatever has been added to amount_received on the model since it was loaded.
 *
 * Limitation: stored amount_owed can be stale (legacy wrote it only when the invoice page was
 * viewed; see docs/pricing.md category A, where it is still 0). A stale 0 makes any payment look
 * like full payment. Replace with a pricing-based implementation when available.
 */
final class StoredAmountOwedBalance implements RegistrationBalance
{
    public function owedCents(ShowRegistration $registration): int
    {
        $storedOwed = self::cents($registration->getOriginal('amount_owed'));
        $receivedDelta = self::cents($registration->amount_received) - self::cents($registration->getOriginal('amount_received'));

        return $storedOwed - $receivedDelta;
    }

    /** Signed cents from a Money2 string such as "-12.30"; null and blanks are 0. */
    public static function cents(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return 0;
        }

        $text = trim(is_string($value) ? $value : sprintf('%.2F', $value));
        if ($text === '') {
            return 0;
        }

        $cents = IpnPayload::parseCents($text);
        if ($cents === null) {
            throw new \InvalidArgumentException('Unreadable money value.');
        }

        return $cents;
    }
}
