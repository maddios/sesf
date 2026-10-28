<?php

namespace App\Services\Shirts;

/**
 * One shirt type as seen by one event. Money is a 2-decimal string ("20.00").
 *
 * selected: the event has a show_shirt_type row for it. showPrice: that row's price (null = default).
 * price: the effective price, showPrice ?? defaultPrice.
 */
final readonly class ShirtOffer
{
    public string $price;

    public function __construct(
        public int $id,
        public string $name,
        public string $defaultPrice,
        public ?string $showPrice,
        public bool $selected,
    ) {
        $this->price = $showPrice ?? $defaultPrice;
    }
}
