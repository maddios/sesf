<?php

namespace App\Services\Judging;

use App\Services\Images\ImageStore;
use Illuminate\Http\UploadedFile;

/**
 * Judging photos on disk (legacy CarJudging::saveJudgingFiles / deleteJudgingFiles / glob):
 * {image_root}/judging/{registrationCarId}/{questionId}/{time}_{uniqid}.jpg, served from
 * /images/judging/... Every upload is re-encoded by ImageStore (EXIF and metadata stripped, no shell).
 * No database row is written; the answer row for the question is what links photos to a sheet.
 */
final class JudgingPhotos
{
    private const NAME_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\.jpg\z/';

    public function __construct(private readonly ImageStore $images) {}

    /** @return list<string> file names, sorted */
    public function list(int $registrationCarId, int $questionId): array
    {
        $names = @scandir($this->directory($registrationCarId, $questionId));
        if ($names === false) {
            return [];
        }

        return array_values(array_filter($names, fn (string $name): bool => $this->isPhotoName($name)
            && is_file($this->directory($registrationCarId, $questionId).'/'.$name)));
    }

    /** Stores the upload and returns its file name. */
    public function store(UploadedFile $file, int $registrationCarId, int $questionId): string
    {
        $name = time().'_'.uniqid().'.jpg';
        $this->images->storeAs($file, $this->directory($registrationCarId, $questionId), $name);

        return $name;
    }

    /** @param  list<string>  $names  names that are not plain photo names are ignored */
    public function delete(int $registrationCarId, int $questionId, array $names): void
    {
        foreach ($names as $name) {
            $path = $this->directory($registrationCarId, $questionId).'/'.$name;
            if ($this->isPhotoName($name) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function url(int $registrationCarId, int $questionId, string $name): string
    {
        return '/images/judging/'.$registrationCarId.'/'.$questionId.'/'.$name;
    }

    private function isPhotoName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    private function directory(int $registrationCarId, int $questionId): string
    {
        return rtrim((string) config('sesf.image_root'), '/').'/judging/'.$registrationCarId.'/'.$questionId;
    }
}
