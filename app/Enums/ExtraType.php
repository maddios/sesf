<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum ExtraType: int
{
    use TriesLegacyValues;

    case Cars = 1;
    case People = 2;
    case Registration = 3;
    case NumericList = 4;

    public function label(): string
    {
        return match ($this) {
            self::Cars => 'Cars',
            self::People => 'Guests',
            self::Registration => 'Registration',
            self::NumericList => 'Numeric List',
        };
    }
}
