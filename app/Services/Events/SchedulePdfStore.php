<?php

namespace App\Services\Events;

use Illuminate\Http\UploadedFile;

/**
 * An event's schedule PDF at the legacy path {sesf.schedule_pdf_root}{showId}.pdf (ScheduleResource reads it).
 *
 * The client's name and MIME type are never used: the content must start with "%PDF-" and finfo must
 * report application/pdf. The file is written to a hidden temp file in the same directory and renamed
 * into place, so readers never see a partial file and a failure keeps the previous PDF.
 */
final class SchedulePdfStore
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @throws SchedulePdfRejected
     */
    public function store(UploadedFile $file, int $showId): void
    {
        $source = $file->isValid() ? $file->getRealPath() : false;
        if ($source === false || ! is_file($source)) {
            throw new SchedulePdfRejected('The upload failed. Please try again.');
        }
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new SchedulePdfRejected('The PDF is larger than 20 MB.');
        }
        if (! $this->isPdf($source)) {
            throw new SchedulePdfRejected('The file must be a PDF.');
        }

        $directory = rtrim((string) config('sesf.schedule_pdf_root'), '/\\');
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new SchedulePdfRejected('The PDF could not be saved. Please try again.');
        }

        $temp = $directory.DIRECTORY_SEPARATOR.'.upload-'.bin2hex(random_bytes(8));
        if (! @copy($source, $temp) || ! @chmod($temp, 0644) || ! @rename($temp, $directory.DIRECTORY_SEPARATOR.$showId.'.pdf')) {
            @unlink($temp);
            throw new SchedulePdfRejected('The PDF could not be saved. Please try again.');
        }
    }

    private function isPdf(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        $magic = $handle === false ? false : fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }

        return $magic === '%PDF-' && (new \finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf';
    }
}
