<?php

namespace App\Services\Images;

/** A source image that passed LegacyImages::locate(): real path inside an allowed root, sniffed type pinned. */
final readonly class LegacyImageSource
{
    public function __construct(
        public string $path,
        public string $mime,
        public string $coder,
        public int $mtime,
        public int $size,
        public int $width,
        public int $height,
    ) {}

    public function extension(): string
    {
        return $this->coder === 'jpeg' ? 'jpg' : $this->coder;
    }
}
