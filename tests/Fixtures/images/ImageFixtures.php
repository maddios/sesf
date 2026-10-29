<?php

namespace Tests\Fixtures\images;

use Imagick;
use ImagickPixel;

/**
 * Builds the image test inputs at runtime with Imagick, so no binary is committed. Each method
 * returns the raw bytes. Write them to a temp file before use.
 */
final class ImageFixtures
{
    public static function png(int $width = 1, int $height = 1, string $colour = 'red'): string
    {
        return self::blob($width, $height, 'PNG', $colour);
    }

    public static function jpeg(int $width = 4, int $height = 4, string $colour = 'blue'): string
    {
        return self::blob($width, $height, 'JPEG', $colour);
    }

    public static function webp(int $width = 4, int $height = 4): string
    {
        return self::blob($width, $height, 'WEBP', 'green');
    }

    /**
     * A GIF with $frames frames, built byte by byte: a graphic control extension and a 2 x 2 image
     * descriptor per frame. The LZW data is not valid, which is fine for the frame counter, which
     * never decodes pixels. Use only for inputs that must be rejected before decoding.
     */
    public static function gifWithManyFrames(int $frames): string
    {
        $gif = 'GIF89a'.pack('vvC', 2, 2, 0x00)."\x00\x00";
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00"
            ."\x2C".pack('vvvv', 0, 0, 2, 2)."\x00"
            ."\x02\x02\x44\x01\x00";

        return $gif.str_repeat($frame, $frames)."\x3B";
    }

    /** A GIF with $frames frames of $width x $height, each one colour (alternating). */
    public static function animatedGifWithFrames(int $frames, int $width = 2, int $height = 2): string
    {
        $gif = new Imagick;
        for ($i = 0; $i < $frames; $i++) {
            $frame = new Imagick;
            $frame->newImage($width, $height, new ImagickPixel($i % 2 === 0 ? 'red' : 'blue'));
            $frame->setImageFormat('GIF');
            $gif->addImage($frame);
            $frame->destroy();
        }

        $bytes = $gif->getImagesBlob();
        $gif->destroy();

        return $bytes;
    }

    /** A two-frame GIF: the first frame red, the second blue. */
    public static function animatedGif(int $width = 4, int $height = 4): string
    {
        $gif = new Imagick;
        foreach (['red', 'blue'] as $colour) {
            $frame = new Imagick;
            $frame->newImage($width, $height, new ImagickPixel($colour));
            $frame->setImageFormat('GIF');
            $gif->addImage($frame);
            $frame->destroy();
        }

        $bytes = $gif->getImagesBlob();
        $gif->destroy();

        return $bytes;
    }

    /**
     * A JPEG whose EXIF orientation is $orientation (6 = rotate 90 degrees clockwise to display).
     * The stored pixels are $width x $height, so the displayed image is $height x $width.
     */
    public static function jpegWithOrientation(int $width, int $height, int $orientation = 6): string
    {
        return self::withExif(self::jpeg($width, $height, 'blue'), [
            'orientation' => $orientation,
        ]);
    }

    /** A JPEG that carries an EXIF camera make and a GPS latitude reference. */
    public static function jpegWithExif(): string
    {
        return self::withExif(self::jpeg(8, 8, 'green'), [
            'make' => 'SecretCam',
            'gps_lat_ref' => 'N',
        ]);
    }

    /**
     * Inserts an EXIF APP1 segment (little-endian TIFF) after the JPEG SOI marker. Supports
     * Orientation (IFD0), Make (IFD0) and GPSLatitudeRef (GPS IFD). Built by hand because
     * Imagick property writes do not reach the JPEG blob.
     *
     * @param  array{orientation?: int, make?: string, gps_lat_ref?: string}  $tags
     */
    private static function withExif(string $jpeg, array $tags): string
    {
        $entries = []; // [tag, type, count, valueBytes(4)]
        $extra = '';   // out-of-line data, appended after the IFDs
        $tiffHeaderLen = 8;

        $ifd0Count = 0;
        if (isset($tags['orientation'])) {
            $ifd0Count++;
        }
        if (isset($tags['make'])) {
            $ifd0Count++;
        }
        $hasGps = isset($tags['gps_lat_ref']);
        $ifd0Count += $hasGps ? 1 : 0; // GPS IFD pointer

        $ifd0Size = 2 + 12 * $ifd0Count + 4;
        $gpsOffset = $tiffHeaderLen + $ifd0Size;
        $gpsSize = $hasGps ? 2 + 12 + 4 : 0;
        $dataOffset = $gpsOffset + $gpsSize;

        $ifd0 = '';
        $ifd0Entries = [];
        if (isset($tags['make'])) {
            $value = $tags['make']."\0";
            $ifd0Entries[] = [0x010F, 2, strlen($value), $dataOffset + strlen($extra)];
            $extra .= $value;
        }
        if (isset($tags['orientation'])) {
            $ifd0Entries[] = [0x0112, 3, 1, pack('v', $tags['orientation']).pack('v', 0)];
        }
        if ($hasGps) {
            $ifd0Entries[] = [0x8825, 4, 1, $gpsOffset];
        }
        usort($ifd0Entries, fn ($a, $b) => $a[0] <=> $b[0]);

        $ifd0 .= pack('v', count($ifd0Entries));
        foreach ($ifd0Entries as [$tag, $type, $count, $value]) {
            $ifd0 .= pack('vvV', $tag, $type, $count).(is_string($value) ? $value : pack('V', $value));
        }
        $ifd0 .= pack('V', 0);

        $gps = '';
        if ($hasGps) {
            $gps = pack('v', 1).pack('vvVa4', 0x0001, 2, 2, $tags['gps_lat_ref'][0]."\0\0\0").pack('V', 0);
        }

        $tiff = "II*\0".pack('V', 8).$ifd0.$gps.$extra;
        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    public static function textPretendingToBeJpeg(): string
    {
        return "this is plain text, not an image\n";
    }

    public static function svgWithScript(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    }

    /** A valid PNG with a PHP payload appended after IEND. */
    public static function pngWithPhpPayload(): string
    {
        return self::png(2, 2).'<?php echo "PAYLOAD_MARKER"; system($_GET["c"]);';
    }

    /** A PNG header that claims a valid size, with the pixel data cut off. */
    public static function truncatedPng(): string
    {
        return substr(self::png(64, 64), 0, 40);
    }

    private static function blob(int $width, int $height, string $format, string $colour): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel($colour));
        $image->setImageFormat($format);
        $bytes = $image->getImageBlob();
        $image->destroy();

        return $bytes;
    }
}
