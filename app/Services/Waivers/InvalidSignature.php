<?php

namespace App\Services\Waivers;

use InvalidArgumentException;

/**
 * The signature image was rejected. The message is safe to show to the signer; the reason is a
 * short machine code for logs and tests (missing, too_large, not_png, dimensions, unreadable).
 */
final class InvalidSignature extends InvalidArgumentException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self('Signature is required.', 'missing');
    }

    public static function tooLarge(): self
    {
        return new self('Signature image is too large.', 'too_large');
    }

    public static function notPng(): self
    {
        return new self('Signature could not be processed.', 'not_png');
    }

    public static function dimensions(): self
    {
        return new self('Signature image is too large.', 'dimensions');
    }

    public static function unreadable(): self
    {
        return new self('Signature could not be processed.', 'unreadable');
    }
}
