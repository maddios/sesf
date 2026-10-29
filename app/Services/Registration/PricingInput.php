<?php

namespace App\Services\Registration;

use App\Enums\UserGuestType;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Everything the calculator needs, already loaded and converted to integer cents.
 * Construction validates: negative counts, quantities, prices or donation are rejected
 * (legacy accepted them; that is a defect we do not carry over). Discount and received are
 * signed ledger values: a refund can make received negative, and legacy rows hold negative
 * discounts (docs/pricing.md, row N).
 */
final readonly class PricingInput
{
    /**
     * @param  list<UserGuestType>  $guests  in registration order (free slots go to the first guests)
     * @param  list<int>  $shirtPricesCents  one entry per shirt (shirt_type.price)
     * @param  list<ExtraLine>  $extras
     * @param  list<string|null>  $carDetails  optional, one per priced car (names only, never priced)
     * @param  list<string|null>  $guestDetails  optional, one per guest
     * @param  list<string|null>  $shirtDetails  optional, one per shirt
     */
    public function __construct(
        public int $carPriceCents,
        public int $adultPriceCents,
        public int $childPriceCents,
        public int $carCount,
        public array $guests = [],
        public array $shirtPricesCents = [],
        public array $extras = [],
        public int $donationCents = 0,
        public int $discountCents = 0,
        public int $receivedCents = 0,
        public array $carDetails = [],
        public array $guestDetails = [],
        public array $shirtDetails = [],
    ) {
        foreach ([
            'Car price' => $carPriceCents,
            'Adult price' => $adultPriceCents,
            'Child price' => $childPriceCents,
            'Car count' => $carCount,
            'Donation' => $donationCents,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("{$name} must not be negative.");
            }
        }

        foreach ($guests as $guest) {
            // PHP arrays are untyped at runtime; the PHPDoc alone does not protect callers.
            if (! $guest instanceof UserGuestType) { // @phpstan-ignore instanceof.alwaysTrue
                throw new InvalidArgumentException('Each guest must be a UserGuestType.');
            }
        }

        foreach ($shirtPricesCents as $price) {
            if (! is_int($price) || $price < 0) { // @phpstan-ignore function.alreadyNarrowedType
                throw new InvalidArgumentException('Shirt prices must be non-negative integer cents.');
            }
        }

        foreach ($extras as $extra) {
            if (! $extra instanceof ExtraLine) { // @phpstan-ignore instanceof.alwaysTrue
                throw new InvalidArgumentException('Each extra must be an ExtraLine.');
            }
        }
    }

    /**
     * Build from legacy decimal dollar amounts (DB strings or floats). A null show
     * price counts as zero, as it did in legacy PHP arithmetic.
     *
     * @param  list<UserGuestType>  $guests
     * @param  list<string|float>  $shirtPrices
     * @param  list<ExtraLine>  $extras
     * @param  list<string|null>  $carDetails
     * @param  list<string|null>  $guestDetails
     * @param  list<string|null>  $shirtDetails
     */
    public static function fromDecimals(
        string|float|null $carPrice,
        string|float|null $adultPrice,
        string|float|null $childPrice,
        int $carCount,
        array $guests = [],
        array $shirtPrices = [],
        array $extras = [],
        string|float|int $donation = 0,
        string|float $discount = 0.0,
        string|float $received = 0.0,
        array $carDetails = [],
        array $guestDetails = [],
        array $shirtDetails = [],
    ): self {
        return new self(
            carPriceCents: Money::toCents($carPrice ?? '0'),
            adultPriceCents: Money::toCents($adultPrice ?? '0'),
            childPriceCents: Money::toCents($childPrice ?? '0'),
            carCount: $carCount,
            guests: $guests,
            shirtPricesCents: array_map(Money::toCents(...), $shirtPrices),
            extras: $extras,
            donationCents: Money::toCents(is_int($donation) ? (string) $donation : $donation),
            discountCents: Money::toSignedCents($discount),
            receivedCents: Money::toSignedCents($received),
            carDetails: $carDetails,
            guestDetails: $guestDetails,
            shirtDetails: $shirtDetails,
        );
    }
}
