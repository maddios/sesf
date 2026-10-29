<?php

namespace App\Services\Registration\Writer;

/**
 * One posted car (validated): the car id and the judging type for this show, or null.
 */
final readonly class CarInput
{
    public function __construct(
        public int $id,
        public ?int $judgingTypeId,
    ) {}
}
