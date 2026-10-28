<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST admin-api/shirts/inventory/{show}/sales: one entry per shirt sold, as shirt type ids.
 */
final class ShirtAdhocSaleRequest extends FormRequest
{
    public const MAX_SHIRTS = 50;

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
            'type_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SHIRTS],
            'type_ids.*' => ['required', 'integer', Rule::exists('shirt_type', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function typeIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('type_ids')));
    }
}
