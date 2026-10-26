<?php

namespace App\Http\Requests\Admin;

use App\Services\Images\ImageStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Multipart image upload for any admin resource (field "file"). The content checks (real image
 * type, dimensions, re-encode) are done by App\Services\Images\ImageStore; this only checks that a
 * file is present and within the size limit. Errors are a standard 422 on "file".
 */
final class AdminImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.intdiv(ImageStore::MAX_BYTES, 1024)],
        ];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
