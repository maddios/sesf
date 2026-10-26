<?php

namespace App\Services\Waivers;

use finfo;
use Imagick;
use Throwable;

/**
 * Validates a signature sent by the signing widget and re-encodes it.
 *
 * Both modes (drawn, and typed text rendered by the widget) send a PNG data URL. In order:
 *  - only "data:image/png;base64,..." is accepted (no SVG, no other type, no bare base64);
 *  - the base64 payload is capped at config('sesf.waiver_signature_base64_max_chars') before decode,
 *    and decoded strictly (any non-base64 byte rejects it);
 *  - the bytes must start with the PNG signature, finfo must say image/png and getimagesizefromstring
 *    must report a PNG within the pixel cap (config('waivers.signature.max_width'/'max_height'));
 *  - the first frame is decoded by ImageMagick, forced to the PNG decoder, under resource limits, and
 *    encoded again with metadata stripped. Only these new bytes are kept, so trailing payloads
 *    (polyglots), text chunks and extra frames are dropped.
 */
final class SignatureImage
{
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /** ImageMagick limits for one decode (restored afterwards). */
    private const RESOURCE_LIMITS = [
        Imagick::RESOURCETYPE_MEMORY => 64 * 1024 * 1024,
        Imagick::RESOURCETYPE_MAP => 64 * 1024 * 1024,
        Imagick::RESOURCETYPE_DISK => 64 * 1024 * 1024,
        Imagick::RESOURCETYPE_AREA => 4000 * 4000,
        Imagick::RESOURCETYPE_TIME => 10,
    ];

    /**
     * @return string the re-encoded PNG as base64 (no data: prefix)
     *
     * @throws InvalidSignature
     */
    public function fromDataUrl(mixed $dataUrl): string
    {
        if (! is_string($dataUrl) || $dataUrl === '') {
            throw InvalidSignature::missing();
        }
        if (preg_match('#\Adata:image/png;base64,#i', $dataUrl) !== 1) {
            throw InvalidSignature::notPng();
        }

        $payload = (string) preg_replace('/\s+/', '', substr($dataUrl, strlen('data:image/png;base64,')));

        return $this->fromBase64($payload);
    }

    /**
     * Same checks for a bare base64 PNG (signatures stored by the legacy app, re-checked before
     * they are put into a regenerated PDF).
     *
     * @throws InvalidSignature
     */
    public function fromBase64(string $payload): string
    {
        if ($payload === '') {
            throw InvalidSignature::missing();
        }
        if (strlen($payload) > $this->maxChars()) {
            throw InvalidSignature::tooLarge();
        }

        $bytes = base64_decode($payload, true);
        if ($bytes === false || $bytes === '') {
            throw InvalidSignature::unreadable();
        }

        $this->assertPng($bytes);

        $encoded = base64_encode($this->reencode($bytes));
        if (strlen($encoded) > $this->maxChars()) {
            throw InvalidSignature::tooLarge();
        }

        return $encoded;
    }

    private function assertPng(string $bytes): void
    {
        if (! str_starts_with($bytes, self::PNG_MAGIC)) {
            throw InvalidSignature::notPng();
        }
        if ((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/png') {
            throw InvalidSignature::notPng();
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[2] !== IMAGETYPE_PNG) {
            throw InvalidSignature::notPng();
        }

        [$width, $height] = $size;
        if ($width < 1 || $height < 1) {
            throw InvalidSignature::unreadable();
        }
        if ($width > (int) config('waivers.signature.max_width') || $height > (int) config('waivers.signature.max_height')) {
            throw InvalidSignature::dimensions();
        }
    }

    private function reencode(string $bytes): string
    {
        $previous = [];
        foreach (self::RESOURCE_LIMITS as $type => $limit) {
            $previous[$type] = Imagick::getResourceLimit($type);
            Imagick::setResourceLimit($type, $limit);
        }

        try {
            $source = new Imagick;
            // Force the PNG decoder, so ImageMagick never sniffs another format from the bytes.
            $source->setFormat('PNG');
            $source->readImageBlob($bytes);
            $source->setIteratorIndex(0);
            $frame = $source->getImage();

            if ($frame->getImageWidth() > (int) config('waivers.signature.max_width')
                || $frame->getImageHeight() > (int) config('waivers.signature.max_height')) {
                throw InvalidSignature::dimensions();
            }

            $frame->stripImage();
            $frame->setImageFormat('PNG32');
            $frame->setOption('png:exclude-chunk', 'bKGD,cHRM,EXIF,gAMA,iCCP,iTXt,sRGB,tEXt,tIME,zCCP,zTXt,date');
            $out = $frame->getImageBlob();
        } catch (InvalidSignature $e) {
            throw $e;
        } catch (Throwable) {
            throw InvalidSignature::unreadable();
        } finally {
            foreach ($previous as $type => $limit) {
                Imagick::setResourceLimit($type, $limit);
            }
        }

        if ($out === '' || ! str_starts_with($out, self::PNG_MAGIC)) {
            throw InvalidSignature::unreadable();
        }

        return $out;
    }

    private function maxChars(): int
    {
        $max = (int) config('sesf.waiver_signature_base64_max_chars', 400000);

        return $max > 0 ? $max : 400000;
    }
}
