<?php

namespace App\Services\Registration;

/**
 * One priced row of a registration invoice. All amounts are integer cents.
 * detail names the item (car, guest name, shirt type, extra) when the caller passed it.
 */
final readonly class LineItem
{
    public int $totalCents;

    public function __construct(
        public LineItemKind $kind,
        public int $unitCents,
        public int $quantity = 1,
        public bool $free = false,
        public ?string $label = null,
        public ?string $detail = null,
    ) {
        $this->totalCents = $unitCents * $quantity;
    }
}
