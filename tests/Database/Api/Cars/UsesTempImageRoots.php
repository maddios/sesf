<?php

namespace Tests\Database\Api\Cars;

/**
 * Points the car and member image roots at a fresh temp directory for each test, and removes it
 * afterwards. The real public/images store is never written by these tests.
 */
trait UsesTempImageRoots
{
    protected string $imageTempRoot;

    protected function setUpUsesTempImageRoots(): void
    {
        $this->imageTempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sesf-api-img-'.bin2hex(random_bytes(6));
        mkdir($this->imageTempRoot.'/cars', 0755, true);
        mkdir($this->imageTempRoot.'/members', 0755, true);

        config([
            'sesf.car_image_root' => $this->imageTempRoot.'/cars/',
            'sesf.member_image_root' => $this->imageTempRoot.'/members/',
        ]);

        $this->beforeApplicationDestroyed(function (): void {
            $this->removeImageTempRoot($this->imageTempRoot);
        });
    }

    private function removeImageTempRoot(string $path): void
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
            $this->removeImageTempRoot($path.DIRECTORY_SEPARATOR.$name);
        }
        @rmdir($path);
    }
}
