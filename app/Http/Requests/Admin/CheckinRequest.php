<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/checkin/registrations/{id}/check-in. override lets an admin check in an unpaid party or one
 * with missing waivers (legacy checkin_override).
 */
final class CheckinRequest extends FormRequest
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
        return ['override' => ['sometimes', 'boolean']];
    }
}
