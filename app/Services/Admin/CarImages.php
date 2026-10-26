<?php

namespace App\Services\Admin;

use App\Services\Images\ImageStore;
use App\Services\Images\ImageUploadException;
use Illuminate\Http\UploadedFile;

/**
 * Car profile photos at {sesf.car_image_root}{id}/profile.jpg (the path Car::imageURL reads). ImageStore does the
 * content checks, the re-encode and the atomic write; this class only picks the root and removes the file.
 */
final class CarImages
{
    private const BASENAME = 'profile';

    public function __construct(private readonly ImageStore $images) {}

    /**
     * @return string the path relative to the root, e.g. "12/profile.jpg"
     *
     * @throws ImageUploadException
     */
    public function store(UploadedFile $file, int $carId): string
    {
        return $this->images->store($file, $this->root(), $carId, self::BASENAME);
    }

    public function delete(int $carId): void
    {
        $directory = $this->root().DIRECTORY_SEPARATOR.$carId;
        $file = $directory.DIRECTORY_SEPARATOR.self::BASENAME.'.jpg';
        if (is_file($file)) {
            @unlink($file);
        }
        if (is_dir($directory) && array_diff(scandir($directory) ?: [], ['.', '..']) === []) {
            @rmdir($directory);
        }
    }

    private function root(): string
    {
        return rtrim((string) config('sesf.car_image_root'), '/\\');
    }
}
