<?php

namespace App\Http\Requests\Admin;

use App\Enums\SponsorType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST admin-api/events/{id}/sponsors: attach one sponsor to the event.
 */
final class EventSponsorRequest extends FormRequest
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
            'sponsor_id' => ['required', 'integer', 'exists:sponsor,id'],
            'type_id' => ['nullable', Rule::enum(SponsorType::class)],
        ];
    }
}
