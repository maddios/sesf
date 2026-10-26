<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/users/{id}/registrations: the event a member is registered for by an administrator.
 */
final class RegisterUserForShowRequest extends FormRequest
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
            'show_id' => ['required', 'integer', 'exists:car_show,id'],
            'car_ids' => ['required', 'array', 'min:1', 'max:20'],
            'car_ids.*' => ['integer', 'distinct', 'exists:car,id'],
        ];
    }

    public function showId(): int
    {
        return (int) $this->validated('show_id');
    }

    /**
     * @return list<int>
     */
    public function carIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('car_ids')));
    }
}
