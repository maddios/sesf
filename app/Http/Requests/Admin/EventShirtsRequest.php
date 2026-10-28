<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT admin-api/events/{id}/shirts: {types: [{id, price}]}, the shirt types the event offers. price is a decimal
 * string within shirt_type.price (float(6,2) unsigned), or null for the type's default price. An empty list is
 * refused: "no rows" means "every type", so offering everything is an explicit full selection.
 */
final class EventShirtsRequest extends FormRequest
{
    public const PRICE_MAX = '9999.99';

    public const EMPTY_SELECTION = 'Select at least one shirt type. To offer every type, use "Select all".';

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
            'types' => ['required', 'array', 'min:1', 'max:200'],
            'types.*' => ['required', 'array:id,price'],
            'types.*.id' => ['required', 'integer', 'distinct', Rule::exists('shirt_type', 'id')],
            'types.*.price' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::PRICE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'types.required' => self::EMPTY_SELECTION,
            'types.min' => self::EMPTY_SELECTION,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'types.*.id' => 'shirt type',
            'types.*.price' => 'price',
        ];
    }

    /**
     * @return array<int, string|null> type id => price override (decimal string) or null
     */
    public function selection(): array
    {
        /** @var list<array{id: int|string, price: int|float|string|null}> $types */
        $types = $this->validated('types');
        $selection = [];
        foreach ($types as $type) {
            $selection[(int) $type['id']] = $type['price'] === null ? null : (string) $type['price'];
        }

        return $selection;
    }
}
