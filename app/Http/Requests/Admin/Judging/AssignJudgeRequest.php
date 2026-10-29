<?php

namespace App\Http\Requests\Admin\Judging;

use Illuminate\Foundation\Http\FormRequest;

/** POST admin-api/judging/shows/{show}/judges (admins only). */
class AssignJudgeRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
