<?php

namespace App\Services\Images;

use Imagick;

/**
 * Produces the resized bytes for a legacy JitImage URL with the Imagick extension (no shell, unlike the
 * legacy "im" driver that ran convert). The coder sniffed by LegacyImages is pinned on read, only the
 * first frame is decoded, EXIF orientation is applied and metadata is stripped. Output keeps the source
 * format, JPEG at the legacy quality 80.
 */
final class LegacyImageRenderer
{
    public const MAX_SOURCE_DIMENSION = 16384;

    public const MAX_SOURCE_AREA = 64 * 1024 * 1024;

    public const JPEG_QUALITY = 80;

    private const RESOURCE_LIMITS = [
        Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_MAP => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_DISK => 512 * 1024 * 1024,
        Imagick::RESOURCETYPE_WIDTH => self::MAX_SOURCE_DIMENSION,
        Imagick::RESOURCETYPE_HEIGHT => self::MAX_SOURCE_DIMENSION,
        Imagick::RESOURCETYPE_AREA => self::MAX_SOURCE_AREA,
        Imagick::RESOURCETYPE_TIME => 20,
    ];

    /** @throws \ImagickException when the source cannot be decoded or encoded */
    public function render(LegacyImageSource $source, LegacyImageSpec $spec): string
    {
        return ImagickLimits::within(self::RESOURCE_LIMITS, function () use ($source, $spec): string {
            $image = new Imagick;

            // Lets libjpeg decode at 1/2, 1/4 or 1/8 scale while staying at least this large on both sides (square,
            // so it still holds after a 90 degree EXIF rotation). Only set when the source is larger on both sides:
            // libjpeg-turbo would otherwise scale up to reach it.
            $hint = 2 * max($spec->width, $spec->height);
            if ($source->coder === 'jpeg' && min($source->width, $source->height) >= $hint) {
                $image->setOption('jpeg:size', $hint.'x'.$hint);
            }

            $image->readImage($source->coder.':'.$source->path.'[0]');
            $image->autoOrient();

            $plan = $spec->plan($image->getImageWidth(), $image->getImageHeight());
            if ($plan['width'] !== $image->getImageWidth() || $plan['height'] !== $image->getImageHeight()) {
                $image->resizeImage($plan['width'], $plan['height'], Imagick::FILTER_LANCZOS, 1);
            }

            if ($plan['crop'] !== null) {
                $crop = $plan['crop'];
                $image->cropImage($crop['width'], $crop['height'], $crop['x'], $crop['y']);
                $image->setImagePage(0, 0, 0, 0);
            }

            $image->stripImage();
            $image->setImageFormat($source->coder);
            if ($source->coder === 'jpeg') {
                $image->setImageCompressionQuality(self::JPEG_QUALITY);
            }

            $bytes = $image->getImageBlob();
            $image->clear();

            if ($bytes === '') {
                throw new \ImagickException('Empty image output.');
            }

            return $bytes;
        });
    }
}
