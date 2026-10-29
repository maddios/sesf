<?php

namespace App\Http\Requests\Admin;

use App\Models\ShirtType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT admin-api/shirts/inventory/{show}/stock: {stock: {<typeId>: count}}. Counts are 0..65535 (the stock is
 * stored as JSON, so the bound is the shirt count a person can hand out, not a column width).
 */
final class ShirtStockRequest extends FormRequest
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
            'stock' => ['required', 'array', 'min:1', 'max:200'],
            'stock.*' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $known = ShirtType::query()->pluck('id')->map(fn ($id): string => (string) $id)->all();
            foreach (array_keys((array) $this->input('stock', [])) as $typeId) {
                if (! in_array((string) $typeId, $known, true)) {
                    $validator->errors()->add('stock.'.$typeId, 'This shirt type does not exist.');
                }
            }
        });
    }

    /**
     * @return array<int, int> type id => count
     */
    public function counts(): array
    {
        /** @var array<string, int> $stock */
        $stock = $this->validated('stock');

        return collect($stock)->mapWithKeys(fn (int $count, string $type): array => [(int) $type => $count])->all();
    }
}
