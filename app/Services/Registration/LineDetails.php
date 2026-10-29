<?php

namespace App\Services\Registration;

use App\Models\Car;
use App\Models\UserGuest;

/**
 * The muted second line under an invoice or summary row: which car or guest it is.
 */
final class LineDetails
{
    /** "Nickname (1969 Camaro)", or "1969 Camaro" without a nickname; null when the car has no year, model or nickname. */
    public static function car(Car $car): ?string
    {
        $name = trim($car->name);
        $nickname = trim((string) $car->nickname);

        if ($nickname === '') {
            return $name === '' ? null : $name;
        }

        return $name === '' ? $nickname : "{$nickname} ({$name})";
    }

    public static function guest(UserGuest $guest): ?string
    {
        return self::person($guest->first, $guest->last);
    }

    public static function person(?string $first, ?string $last): ?string
    {
        $name = trim(trim((string) $first).' '.trim((string) $last));

        return $name === '' ? null : $name;
    }
}
