<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum UserType: int
{
    use TriesLegacyValues;

    case Regular = 1;
    case Judge = 2;
    case Administrator = 3;

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Judge => 'Judge',
            self::Administrator => 'Administrator',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Administrator;
    }

    public function isJudge(): bool
    {
        return $this === self::Judge;
    }
}
