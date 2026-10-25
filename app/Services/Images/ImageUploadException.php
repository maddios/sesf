<?php

namespace App\Services\Images;

use RuntimeException;

/**
 * A rejected or failed image upload. The message is safe to show to the member: it never
 * contains a path, a MIME type from the client, or a library error.
 */
final class ImageUploadException extends RuntimeException
{
    public static function invalidUpload(): self
    {
        return new self('The upload failed. Please try again.');
    }

    public static function tooLarge(): self
    {
        return new self('The image is larger than 12 MB.');
    }

    public static function unsupportedType(): self
    {
        return new self('The file must be a JPEG, PNG, GIF or WebP image.');
    }

    public static function dimensionsTooLarge(): self
    {
        return new self('The image is larger than 8000 x 8000 pixels.');
    }

    public static function unreadable(): self
    {
        return new self('The image could not be read. Please try a different file.');
    }

    public static function saveFailed(): self
    {
        return new self('The image could not be saved. Please try again.');
    }
}
