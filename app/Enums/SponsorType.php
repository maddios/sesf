<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum SponsorType: int
{
    use TriesLegacyValues;

    case M = 1;
    case Gold = 2;
    case Silber = 3;
    case Bronzit = 4;

    /** Labels are the intentional legacy spellings. */
    public function label(): string
    {
        return match ($this) {
            self::M => 'M',
            self::Gold => 'Gold',
            self::Silber => 'Silber',
            self::Bronzit => 'Bronzit',
        };
    }
}
