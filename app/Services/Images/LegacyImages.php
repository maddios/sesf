<?php

namespace App\Services\Images;

use Throwable;

/**
 * Source lookup and on-disk cache for the legacy JitImage URLs (/images/{mode}/{w}/{h}[/{g}]/{source}).
 * Settings: config('sesf.legacy_images').
 *
 * A source is served only when every path segment is a plain name (no dot segments, dotfiles, backslashes
 * or control characters), the extension is allowlisted, the real path (symlinks resolved) is a file inside
 * one of the configured roots, and the sniffed content is JPEG, PNG or GIF within the size limits.
 *
 * Rendered files are cached under cache_dir, keyed by the transformation, the source path, its mtime and
 * size, so a replaced source gets a new entry. Writes go to a temp file that is renamed into place.
 */
final class LegacyImages
{
    public const MAX_SOURCE_BYTES = 40 * 1024 * 1024;

    /** Bump to invalidate every cached rendering after a change to the renderer. */
    private const CACHE_VERSION = 1;

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
    ];

    private const SEGMENT_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9 _.,&()+\-]*\z/';

    public function __construct(private readonly LegacyImageRenderer $renderer) {}

    public function parse(string $path): ?LegacyImageSpec
    {
        return LegacyImageSpec::parse($path, config('sesf.legacy_images.sizes'));
    }

    public function locate(string $source): ?LegacyImageSource
    {
        foreach (explode('/', $source) as $segment) {
            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                return null;
            }
        }

        if (! in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return null;
        }

        $real = realpath(rtrim(config('sesf.legacy_images.base'), '/').'/'.$source);
        if ($real === false || ! is_file($real) || ! $this->insideRoots($real)) {
            return null;
        }

        return $this->inspect($real);
    }

    public function cacheKey(LegacyImageSpec $spec, LegacyImageSource $source): string
    {
        return hash('sha256', implode("\0", [
            self::CACHE_VERSION, $spec->key(), $source->path, $source->mtime, $source->size,
        ]));
    }

    public function cachePath(string $key, LegacyImageSource $source): string
    {
        return rtrim(config('sesf.legacy_images.cache_dir'), '/').'/'.substr($key, 0, 2).'/'.$key.'.'.$source->extension();
    }

    /** Renders into the cache. Returns false when the source cannot be decoded or the write fails. */
    public function render(LegacyImageSpec $spec, LegacyImageSource $source, string $cachePath): bool
    {
        try {
            $bytes = $this->renderer->render($source, $spec);
        } catch (Throwable) {
            return false;
        }

        return $this->writeAtomically($cachePath, $bytes);
    }

    private function insideRoots(string $real): bool
    {
        foreach (config('sesf.legacy_images.roots') as $root) {
            $rootReal = realpath($root);
            if ($rootReal !== false && str_starts_with($real, rtrim($rootReal, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function inspect(string $real): ?LegacyImageSource
    {
        $size = @filesize($real);
        $mtime = @filemtime($real);
        if ($size === false || $mtime === false || $size === 0 || $size > self::MAX_SOURCE_BYTES) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real);
        if ($mime === false || ! isset(self::ALLOWED_MIME[$mime])) {
            return null;
        }

        $info = @getimagesize($real);
        if ($info === false || $info['mime'] !== $mime) {
            return null;
        }

        [$width, $height] = [$info[0], $info[1]];
        $max = LegacyImageRenderer::MAX_SOURCE_DIMENSION;
        if ($width < 1 || $height < 1 || $width > $max || $height > $max || $width * $height > LegacyImageRenderer::MAX_SOURCE_AREA) {
            return null;
        }

        return new LegacyImageSource($real, $mime, self::ALLOWED_MIME[$mime], $mtime, $size, $width, $height);
    }

    private function writeAtomically(string $path, string $bytes): bool
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        $temp = $directory.'/.'.bin2hex(random_bytes(12)).'.tmp';
        if (@file_put_contents($temp, $bytes) !== strlen($bytes) || ! @rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }
}
