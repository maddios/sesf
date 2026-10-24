<?php

namespace App\Services\Registration;

/**
 * Calculator output. All amounts are integer cents.
 *
 * owedCents = totalCents - (receivedCents + discountCents). It is signed: a negative
 * value is a credit (overpaid, or discount larger than the total), exactly as legacy.
 */
final readonly class PricingResult
{
    /**
     * @param  list<LineItem>  $lineItems
     */
    public function __construct(
        public array $lineItems,
        public int $carsCents,
        public int $guestsCents,
        public int $shirtsCents,
        public int $extrasCents,
        public int $donationCents,
        public int $totalCents,
        public int $discountCents,
        public int $receivedCents,
        public int $owedCents,
        public int $freeGuestSlots,
        public int $freeGuestSlotsUsed,
    ) {}

    /** True when nothing is left to pay (legacy update_is_paid() only flips is_paid to 0 when owed > 0). */
    public function isSettled(): bool
    {
        return $this->owedCents <= 0;
    }

    /** @return list<LineItem> */
    public function itemsOfKind(LineItemKind $kind): array
    {
        return array_values(array_filter($this->lineItems, fn (LineItem $item) => $item->kind === $kind));
    }
}
