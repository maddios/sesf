<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum GalleryItemType: int
{
    use TriesLegacyValues;

    case Image = 1;
    case Video = 2;
    case Other = 3;

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Other => 'Other',
        };
    }
}
