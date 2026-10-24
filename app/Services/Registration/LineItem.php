<?php

namespace App\Services\Registration;

/**
 * One priced row of a registration invoice. All amounts are integer cents.
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
    ) {
        $this->totalCents = $unitCents * $quantity;
    }
}
