<?php

namespace App\Services\Registration;

use App\Enums\UserGuestType;

/**
 * Registration pricing, ported from legacy RegistrationPayment::calculate().
 *
 * Pure: no Eloquent, no DB, no facades. Integer cents throughout, so there is no
 * rounding inside the calculation; rounding happens once, when decimal inputs are
 * converted with Money::toCents (half up). See docs/pricing.md.
 */
final class PricingCalculator
{
    public function calculate(PricingInput $input): PricingResult
    {
        $items = [];

        // Every car costs price_car. The first car grants no free guest; each further car grants one.
        $freeSlots = max(0, $input->carCount - 1);
        $carsCents = 0;
        for ($i = 0; $i < $input->carCount; $i++) {
            $items[] = new LineItem(LineItemKind::Car, $input->carPriceCents);
            $carsCents += $input->carPriceCents;
        }

        // Guests are free while slots remain, in registration order, whatever their type.
        $slotsLeft = $freeSlots;
        $guestsCents = 0;
        foreach ($input->guests as $guest) {
            if ($slotsLeft > 0) {
                $slotsLeft--;
                $items[] = new LineItem(LineItemKind::Guest, 0, free: true, label: $guest->name);

                continue;
            }

            $price = $guest === UserGuestType::Adult ? $input->adultPriceCents : $input->childPriceCents;
            $items[] = new LineItem(LineItemKind::Guest, $price, label: $guest->name);
            $guestsCents += $price;
        }

        $shirtsCents = 0;
        foreach ($input->shirtPricesCents as $price) {
            $items[] = new LineItem(LineItemKind::Shirt, $price);
            $shirtsCents += $price;
        }

        $extrasCents = 0;
        foreach ($input->extras as $extra) {
            $item = new LineItem(LineItemKind::Extra, $extra->priceCents, $extra->quantity);
            $items[] = $item;
            $extrasCents += $item->totalCents;
        }

        if ($input->donationCents > 0) {
            $items[] = new LineItem(LineItemKind::Donation, $input->donationCents);
        }

        $total = $carsCents + $guestsCents + $shirtsCents + $extrasCents + $input->donationCents;

        return new PricingResult(
            lineItems: $items,
            carsCents: $carsCents,
            guestsCents: $guestsCents,
            shirtsCents: $shirtsCents,
            extrasCents: $extrasCents,
            donationCents: $input->donationCents,
            totalCents: $total,
            discountCents: $input->discountCents,
            receivedCents: $input->receivedCents,
            owedCents: $total - ($input->receivedCents + $input->discountCents),
            freeGuestSlots: $freeSlots,
            freeGuestSlotsUsed: $freeSlots - $slotsLeft,
        );
    }
}
