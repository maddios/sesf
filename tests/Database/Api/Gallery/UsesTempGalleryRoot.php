<?php

namespace Tests\Database\Api\Gallery;

/**
 * Points the gallery image root at a fresh temp directory for each test, and removes it
 * afterwards. The real public/images store is never written by these tests.
 */
trait UsesTempGalleryRoot
{
    protected string $galleryTempRoot;

    protected function setUpUsesTempGalleryRoot(): void
    {
        $this->galleryTempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-api-gallery-'.bin2hex(random_bytes(6));
        mkdir($this->galleryTempRoot, 0755, true);

        config(['sesf.gallery_image_root' => $this->galleryTempRoot.'/']);

        $this->beforeApplicationDestroyed(function (): void {
            $this->removeGalleryTempRoot($this->galleryTempRoot);
        });
    }

    private function removeGalleryTempRoot(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        @chmod($path, 0755);
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $this->removeGalleryTempRoot($path.DIRECTORY_SEPARATOR.$name);
        }
        @rmdir($path);
    }
}
