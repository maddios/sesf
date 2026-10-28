<?php

namespace App\Http\Requests\Admin\Judging;

use Illuminate\Foundation\Http\FormRequest;

/** POST admin-api/judging/shows/{show}/entries. Route middleware judge_for_show authorizes the show. */
class AssignJudgingTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'car_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'judging_type_id' => ['required', 'integer', 'min:1', 'max:255'],
        ];
    }
}
