<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/agenda/reorder and POST admin-api/locations/reorder: the event and its items in the new order.
 * Which items belong to the event is checked by App\Services\Admin\ShowItemOrder (422 on ids).
 */
final class ReorderShowItemsRequest extends FormRequest
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
            'show_id' => ['required', 'integer', 'exists:car_show,id'],
            'ids' => ['required', 'array', 'min:1', 'max:255'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('ids')));
    }

    public function showId(): int
    {
        return (int) $this->validated('show_id');
    }
}
