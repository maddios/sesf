<?php

namespace App\Http\Requests\Admin;

use App\Services\Events\SchedulePdfStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Multipart schedule PDF upload for an event (field "file"). The content checks are SchedulePdfStore's.
 */
final class SchedulePdfRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:'.intdiv(SchedulePdfStore::MAX_BYTES, 1024)],
        ];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
