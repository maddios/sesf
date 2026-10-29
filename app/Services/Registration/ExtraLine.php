<?php

namespace App\Services\Registration;

use App\Support\Money;
use InvalidArgumentException;

/**
 * A show extra on a registration: unit price (show_extra.price) times num_extras.
 * The extra's type (App\Enums\ExtraType) does not affect the price.
 */
final readonly class ExtraLine
{
    public function __construct(
        public int $priceCents,
        public int $quantity,
        public ?string $name = null,
    ) {
        if ($priceCents < 0) {
            throw new InvalidArgumentException('Extra price must not be negative.');
        }
        if ($quantity < 0) {
            throw new InvalidArgumentException('Extra quantity must not be negative.');
        }
    }

    public static function fromDecimal(string|float $price, int $quantity, ?string $name = null): self
    {
        return new self(Money::toCents($price), $quantity, $name);
    }
}
