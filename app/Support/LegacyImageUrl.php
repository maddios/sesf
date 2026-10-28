<?php

namespace App\Support;

/**
 * Builds a legacy JitImage resize URL, e.g. LegacyImageUrl::fit('images/cars/3/profile.jpg?123', 500, 500)
 * gives "/images/4/500/500/images/cars/3/profile.jpg?123". The size must be listed in
 * config('sesf.legacy_images.sizes') or the URL is a 404.
 */
final class LegacyImageUrl
{
    /** Mode 4: shrink to fit inside the box, never enlarge. */
    public static function fit(string $path, int $width, int $height): string
    {
        return self::build('4/'.$width.'/'.$height, $path);
    }

    /** Mode 2: cover the box, then crop at gravity 1-9 (5 is centre). */
    public static function crop(string $path, int $width, int $height, int $gravity = 5): string
    {
        return self::build('2/'.$width.'/'.$height.'/'.$gravity, $path);
    }

    private static function build(string $spec, string $path): string
    {
        return '/images/'.$spec.'/'.ltrim($path, '/');
    }
}
