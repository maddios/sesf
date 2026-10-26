<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Imagick;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Stores a member's or car's profile image as a re-encoded JPEG under a fixed root.
 *
 * Replaces the legacy Imagine/Imagick save in CarProfile::postProfileImage and
 * MemberProfile::postProfileImage. The output paths are the legacy ones: {root}/{id}/profile.jpg,
 * e.g. config('sesf.car_image_root') + "12/profile.jpg".
 *
 * What it does, in order:
 *  - the client's file name and MIME type are never used: the content is checked with finfo and
 *    getimagesize, then decoded for real (JPEG, PNG, GIF or WebP only);
 *  - size (12 MB) and dimensions (8000 x 8000 px) are checked before the full decode;
 *  - only the first frame is kept, EXIF orientation is applied, and the image is re-encoded as
 *    JPEG (quality 85) with all metadata stripped, so EXIF, GPS and any appended payload are gone;
 *  - the file is written to a hidden temp file in the same directory and renamed into place, so a
 *    reader never sees a partial file and a failure leaves the previous image untouched;
 *  - older variants of the same base name (for example profile.png) are removed.
 *
 * No shell is run. The id is an int and the base name is checked against a strict pattern, so
 * nothing from the request can change the destination path.
 */
final class ImageStore
{
    public const MAX_BYTES = 12 * 1024 * 1024;

    public const MAX_DIMENSION = 8000;

    public const JPEG_QUALITY = 85;

    /** Most frames an animation may have. Frames past this are not counted, so the scan stops here. */
    public const MAX_FRAMES = 100;

    /** Pixels allowed across all frames of an animation (frame count x width x height). */
    public const MAX_ANIMATION_PIXELS = 256 * 1024 * 1024;

    /** Pixels allowed in one decoded frame (64 megapixels, the same as 8000 x 8000). */
    public const MAX_AREA = 64 * 1024 * 1024;

    /** ImageMagick resource limits applied for one decode, then restored. */
    private const RESOURCE_LIMITS = [
        Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_MAP => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_DISK => 512 * 1024 * 1024,
        Imagick::RESOURCETYPE_WIDTH => self::MAX_DIMENSION,
        Imagick::RESOURCETYPE_HEIGHT => self::MAX_DIMENSION,
        Imagick::RESOURCETYPE_AREA => self::MAX_AREA,
        Imagick::RESOURCETYPE_TIME => 20,
    ];

    /** Chunk and block walk cap, so a crafted file cannot make the frame scan run long. */
    private const MAX_SCAN_STEPS = 200000;

    /** @var array<string, true> */
    private const ALLOWED_MIME = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/gif' => true,
        'image/webp' => true,
    ];

    private const BASENAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    private ?ImageManager $manager = null;

    private static ?\finfo $finfo = null;

    /**
     * Stores the upload as {root}/{id}/{basename}.jpg.
     *
     * @return string the path relative to $root, e.g. "12/profile.jpg"
     *
     * @throws ImageUploadException when the upload is not a valid image or cannot be saved
     */
    public function store(UploadedFile $file, string $root, int $id, string $basename = 'profile'): string
    {
        $this->assertTarget($id, $basename);

        if (! $file->isValid()) {
            throw ImageUploadException::invalidUpload();
        }

        $source = $file->getRealPath();
        if ($source === false || ! is_file($source)) {
            throw ImageUploadException::invalidUpload();
        }

        $size = $file->getSize();
        if ($size === false || $size > self::MAX_BYTES) {
            throw ImageUploadException::tooLarge();
        }

        $this->assertImageContent($source);

        $bytes = $this->reencode($source);

        $directory = $this->directoryFor($root, $id);
        $final = $directory.DIRECTORY_SEPARATOR.$basename.'.jpg';

        $this->writeAtomically($directory, $final, $bytes);
        $this->removeVariants($directory, $basename, $final);

        return $id.'/'.$basename.'.jpg';
    }

    /**
     * Removes every stored variant of {basename} for this id (profile.jpg, profile.png, ...).
     * A missing directory is not an error.
     */
    public function delete(string $root, int $id, string $basename = 'profile'): void
    {
        $this->assertTarget($id, $basename);

        $directory = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$id;
        if (! is_dir($directory)) {
            return;
        }

        $this->removeVariants($directory, $basename, null);
    }

    /**
     * Checks the content, not the name: the MIME type from finfo and the header from getimagesize
     * must both be an allowed image type, and the dimensions must be within the limit.
     */
    private function assertImageContent(string $source): void
    {
        $mime = self::finfo()->file($source);
        if ($mime === false || ! isset(self::ALLOWED_MIME[$mime])) {
            throw ImageUploadException::unsupportedType();
        }

        $info = @getimagesize($source);
        if ($info === false || ! isset(self::ALLOWED_MIME[$info['mime']])) {
            throw ImageUploadException::unsupportedType();
        }

        [$width, $height] = [$info[0], $info[1]];
        if ($width < 1 || $height < 1) {
            throw ImageUploadException::unreadable();
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw ImageUploadException::dimensionsTooLarge();
        }

        $frames = $this->countFrames($source, $info['mime']);
        if ($frames > self::MAX_FRAMES || $frames * $width * $height > self::MAX_ANIMATION_PIXELS) {
            throw ImageUploadException::animationTooLarge();
        }
    }

    /**
     * Counts the frames from the file structure without decoding any pixels. Only GIF and WebP
     * can be animated. Reads are streamed and skip image data, so memory does not grow with the
     * file. Stops once the count passes MAX_FRAMES.
     */
    private function countFrames(string $source, string $mime): int
    {
        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw ImageUploadException::invalidUpload();
        }

        try {
            $frames = match ($mime) {
                'image/gif' => $this->countGifFrames($handle),
                'image/webp' => $this->countWebpFrames($handle),
                default => 1,
            };
        } finally {
            fclose($handle);
        }

        return max(1, $frames);
    }

    /**
     * GIF: skip the header and global colour table, then walk the blocks. Each image descriptor
     * (0x2C) is one frame; extensions (0x21) are skipped by their sub-block chain.
     *
     * @param  resource  $handle
     */
    private function countGifFrames($handle): int
    {
        $header = fread($handle, 13);
        if (! is_string($header) || strlen($header) < 13) {
            return 1;
        }

        $flags = ord($header[10]);
        if (($flags & 0x80) !== 0) {
            fseek($handle, 3 * (2 ** (($flags & 0x07) + 1)), SEEK_CUR);
        }

        $frames = 0;
        for ($steps = 0; $steps < self::MAX_SCAN_STEPS; $steps++) {
            $byte = fgetc($handle);
            if ($byte === false) {
                break;
            }

            $code = ord($byte);
            if ($code === 0x3B) {
                break;
            }

            if ($code === 0x2C) {
                $frames++;
                if ($frames > self::MAX_FRAMES) {
                    return $frames;
                }

                $descriptor = fread($handle, 9);
                if (! is_string($descriptor) || strlen($descriptor) < 9) {
                    break;
                }

                $local = ord($descriptor[8]);
                if (($local & 0x80) !== 0) {
                    fseek($handle, 3 * (2 ** (($local & 0x07) + 1)), SEEK_CUR);
                }

                if (fgetc($handle) === false || ! $this->skipGifSubBlocks($handle)) {
                    break;
                }
            } elseif ($code === 0x21) {
                if (fgetc($handle) === false || ! $this->skipGifSubBlocks($handle)) {
                    break;
                }
            } else {
                break;
            }
        }

        return $frames;
    }

    /**
     * @param  resource  $handle
     */
    private function skipGifSubBlocks($handle): bool
    {
        for ($steps = 0; $steps < self::MAX_SCAN_STEPS; $steps++) {
            $size = fgetc($handle);
            if ($size === false) {
                return false;
            }

            $length = ord($size);
            if ($length === 0) {
                return true;
            }

            fseek($handle, $length, SEEK_CUR);
        }

        return false;
    }

    /**
     * WebP: walk the RIFF chunks. Each ANMF chunk is one frame of an animation. A still image
     * has none.
     *
     * @param  resource  $handle
     */
    private function countWebpFrames($handle): int
    {
        $header = fread($handle, 12);
        if (! is_string($header) || strlen($header) < 12 || substr($header, 8, 4) !== 'WEBP') {
            return 1;
        }

        $frames = 0;
        for ($steps = 0; $steps < self::MAX_SCAN_STEPS; $steps++) {
            $chunk = fread($handle, 8);
            if (! is_string($chunk) || strlen($chunk) < 8) {
                break;
            }

            $size = unpack('V', substr($chunk, 4, 4))[1];
            if (substr($chunk, 0, 4) === 'ANMF') {
                $frames++;
                if ($frames > self::MAX_FRAMES) {
                    return $frames;
                }
            }

            fseek($handle, $size + ($size % 2), SEEK_CUR);
        }

        return $frames;
    }

    /**
     * Decodes the first frame only, under ImageMagick resource limits that are restored afterwards.
     * Applies EXIF orientation and encodes a metadata-free JPEG.
     */
    private function reencode(string $source): string
    {
        $previous = $this->applyResourceLimits();

        try {
            $imagick = new Imagick;
            // "[0]" selects the first frame at read time, so the other frames are never decoded.
            $imagick->readImage($source.'[0]');
            $frame = $imagick->getImage();

            if ($frame->getImageWidth() * $frame->getImageHeight() > self::MAX_AREA) {
                throw ImageUploadException::dimensionsTooLarge();
            }

            $image = $this->manager()->read($frame)->orient();
            $bytes = $image->toJpeg(quality: self::JPEG_QUALITY, strip: true)->toString();
        } catch (ImageUploadException $e) {
            throw $e;
        } catch (Throwable) {
            throw ImageUploadException::unreadable();
        } finally {
            $this->restoreResourceLimits($previous);
        }

        if ($bytes === '') {
            throw ImageUploadException::unreadable();
        }

        return $bytes;
    }

    /**
     * Sets the decode limits and returns the values they replaced.
     *
     * @return array<int, int>
     */
    private function applyResourceLimits(): array
    {
        $previous = [];
        foreach (self::RESOURCE_LIMITS as $type => $limit) {
            $previous[$type] = Imagick::getResourceLimit($type);
            Imagick::setResourceLimit($type, $limit);
        }

        return $previous;
    }

    /**
     * @param  array<int, int>  $previous
     */
    private function restoreResourceLimits(array $previous): void
    {
        foreach ($previous as $type => $limit) {
            Imagick::setResourceLimit($type, $limit);
        }
    }

    /**
     * Writes to a hidden temp file in the same directory, then renames it over the target. The
     * temp file is removed on any failure.
     */
    private function writeAtomically(string $directory, string $final, string $bytes): void
    {
        $temp = $directory.DIRECTORY_SEPARATOR.'.'.bin2hex(random_bytes(12)).'.tmp';
        $renamed = false;

        try {
            $written = @file_put_contents($temp, $bytes, LOCK_EX);
            if ($written !== strlen($bytes)) {
                throw ImageUploadException::saveFailed();
            }

            @chmod($temp, 0644);

            if (! @rename($temp, $final)) {
                throw ImageUploadException::saveFailed();
            }
            $renamed = true;
        } finally {
            if (! $renamed && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Deletes files named {basename}.* in the directory, except $keep. Uses scandir rather than
     * glob so that a bracket in the root path is never a pattern.
     */
    private function removeVariants(string $directory, string $basename, ?string $keep): void
    {
        $names = @scandir($directory);
        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if (! str_starts_with($name, $basename.'.')) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if ($path === $keep || ! is_file($path)) {
                continue;
            }

            @unlink($path);
        }
    }

    /**
     * Creates {root}/{id} with mode 0755 when it does not exist.
     */
    private function directoryFor(string $root, int $id): string
    {
        $directory = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$id;

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw ImageUploadException::saveFailed();
        }

        return $directory;
    }

    private function assertTarget(int $id, string $basename): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Image owner id must be a positive integer.');
        }

        if (preg_match(self::BASENAME_PATTERN, $basename) !== 1) {
            throw new \InvalidArgumentException('Invalid image base name.');
        }
    }

    /**
     * One finfo handle per process. Opening one loads libmagic's database (several MB), so a new
     * handle per upload would cost that load every time.
     */
    private static function finfo(): \finfo
    {
        return self::$finfo ??= new \finfo(FILEINFO_MIME_TYPE);
    }

    private function manager(): ImageManager
    {
        return $this->manager ??= new ImageManager(new ImagickDriver);
    }
}
