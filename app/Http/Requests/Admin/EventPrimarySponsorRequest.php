<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT admin-api/events/{id}/primary-sponsor: one sponsor of the event, or null to clear it.
 */
final class EventPrimarySponsorRequest extends FormRequest
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
            'sponsor_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }
}
