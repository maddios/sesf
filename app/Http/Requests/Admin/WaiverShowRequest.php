<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/waivers/reminders and waivers/regenerate-pdfs: one event (show_id).
 */
final class WaiverShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'show_id' => ['required', 'integer', 'min:1', 'exists:car_show,id'],
        ];
    }
}
