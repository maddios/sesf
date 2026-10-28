<?php

namespace App\Http\Requests\Admin\Judging;

use Illuminate\Foundation\Http\FormRequest;

/** PUT admin-api/judging/shows/{show}/release (admins only). */
class ReleaseJudgingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'released' => ['required', 'boolean'],
        ];
    }
}
