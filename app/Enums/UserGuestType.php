<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum UserGuestType: int
{
    use TriesLegacyValues;

    case Adult = 1;
    case Child = 2;

    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Adult',
            self::Child => 'Child (Under 13)',
        };
    }
}
