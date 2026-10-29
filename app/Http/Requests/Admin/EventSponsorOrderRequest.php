<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT admin-api/events/{id}/sponsors/order: the event's show_sponsor ids in their new order.
 * The controller checks that the list is exactly this event's links.
 */
final class EventSponsorOrderRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }
}
