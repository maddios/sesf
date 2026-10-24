<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum PaymentMethod: int
{
    use TriesLegacyValues;

    case PayPal = 1;
    case Check = 2;

    public function label(): string
    {
        return match ($this) {
            self::PayPal => 'PayPal',
            self::Check => 'Check',
        };
    }
}
