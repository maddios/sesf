<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum JudgingType: int
{
    use TriesLegacyValues;

    case Clean = 1;
    case SuperClean = 2;
    case Concours = 3;

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Clean',
            self::SuperClean => 'Super Clean',
            self::Concours => 'Concours',
        };
    }
}
