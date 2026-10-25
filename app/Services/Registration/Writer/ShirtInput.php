<?php

namespace App\Services\Registration\Writer;

/**
 * One posted shirt (validated). id null = new shirt. deleted = remove the existing shirt.
 * On a deleted entry only id is meaningful.
 */
final readonly class ShirtInput
{
    public function __construct(
        public ?int $id,
        public bool $deleted,
        public int $typeId = 0,
    ) {}
}
