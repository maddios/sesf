<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/shirts/{id}/handed-out: {handed_out: true|false}.
 */
final class ShirtHandedOutRequest extends FormRequest
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
        return ['handed_out' => ['required', 'boolean']];
    }

    public function handedOut(): bool
    {
        return $this->boolean('handed_out');
    }
}
