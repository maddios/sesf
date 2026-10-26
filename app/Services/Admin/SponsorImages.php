<?php

namespace App\Services\Admin;

use App\Services\Images\ImageStore;
use App\Services\Images\ImageUploadException;
use Illuminate\Http\UploadedFile;

/**
 * Sponsor logos at the legacy flat path {sesf.sponsor_image_root}{id}.jpg (the public pages and
 * Sponsor::imageURL read it there).
 *
 * ImageStore writes {root}/{id}/{basename}.jpg, so the upload is stored in a private staging
 * directory inside the sponsor root (same filesystem) and then renamed into place: the swap is
 * atomic and a failed upload leaves the previous logo untouched. All content checks and the
 * re-encode are ImageStore's.
 */
final class SponsorImages
{
    public function __construct(private readonly ImageStore $images) {}

    /**
     * @return string the stored file name, e.g. "12.jpg"
     *
     * @throws ImageUploadException
     */
    public function store(UploadedFile $file, int $sponsorId): string
    {
        $root = $this->root();
        if (! is_dir($root) && ! @mkdir($root, 0755, true) && ! is_dir($root)) {
            throw ImageUploadException::saveFailed();
        }

        $staging = $root.DIRECTORY_SEPARATOR.'.staging-'.bin2hex(random_bytes(8));
        if (! @mkdir($staging, 0700)) {
            throw ImageUploadException::saveFailed();
        }

        try {
            $relative = $this->images->store($file, $staging, $sponsorId, 'sponsor');
            $final = $this->path($sponsorId);
            if (! @rename($staging.DIRECTORY_SEPARATOR.$relative, $final)) {
                throw ImageUploadException::saveFailed();
            }
            @chmod($final, 0644);
        } finally {
            $this->removeTree($staging);
        }

        return $sponsorId.'.jpg';
    }

    public function delete(int $sponsorId): void
    {
        $path = $this->path($sponsorId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function path(int $sponsorId): string
    {
        return $this->root().DIRECTORY_SEPARATOR.$sponsorId.'.jpg';
    }

    private function root(): string
    {
        return rtrim((string) config('sesf.sponsor_image_root'), '/\\');
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $this->removeTree($path.DIRECTORY_SEPARATOR.$name);
        }
        @rmdir($path);
    }
}
