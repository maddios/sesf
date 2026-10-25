<?php

namespace App\Services\Registration\Writer;

use App\Enums\UserGuestType;

/**
 * One posted guest (validated). id null = new guest. deleted = remove the existing guest.
 * On a deleted entry only id is meaningful.
 */
final readonly class GuestInput
{
    public function __construct(
        public ?int $id,
        public bool $deleted,
        public string $first = '',
        public string $last = '',
        public ?string $email = null,
        public UserGuestType $type = UserGuestType::Adult,
    ) {}
}
