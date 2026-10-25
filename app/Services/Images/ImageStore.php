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

    /** @var array<string, true> */
    private const ALLOWED_MIME = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/gif' => true,
        'image/webp' => true,
    ];

    private const BASENAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    private ?ImageManager $manager = null;

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
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
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
    }

    /**
     * Decodes the first frame, applies EXIF orientation, and encodes a metadata-free JPEG.
     */
    private function reencode(string $source): string
    {
        try {
            $imagick = new Imagick;
            $handle = fopen($source, 'rb');
            if ($handle === false) {
                throw ImageUploadException::invalidUpload();
            }
            try {
                $imagick->readImageFile($handle);
            } finally {
                fclose($handle);
            }

            // Animated GIF and similar: keep the first frame only. Encoding several frames as
            // JPEG would write several concatenated JPEG streams.
            $imagick->setIteratorIndex(0);
            $frame = $imagick->getImage();

            $image = $this->manager()->read($frame)->orient();
            $bytes = $image->toJpeg(quality: self::JPEG_QUALITY, strip: true)->toString();
        } catch (ImageUploadException $e) {
            throw $e;
        } catch (Throwable) {
            throw ImageUploadException::unreadable();
        }

        if ($bytes === '') {
            throw ImageUploadException::unreadable();
        }

        return $bytes;
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

    private function manager(): ImageManager
    {
        return $this->manager ??= new ImageManager(new ImagickDriver);
    }
}
