<?php

namespace App\Http\Requests\Admin;

use App\Enums\SponsorType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT admin-api/events/{id}/sponsors/{link}: the tier of one event sponsor (show_sponsor.type_id), or null for none.
 * Tier M is the main sponsor that the home page lists as primary.
 */
final class EventSponsorTypeRequest extends FormRequest
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
            'type_id' => ['present', 'nullable', Rule::enum(SponsorType::class)],
        ];
    }
}
