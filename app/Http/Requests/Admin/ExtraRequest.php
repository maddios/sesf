<?php

namespace App\Http\Requests\Admin;

use App\Enums\ExtraType;
use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST admin-api/extras (with show_id) and PUT admin-api/extras/{id}. The event is set on create only.
 * auth_key is the public key of the extras landing URL (unique, URL-safe). Widths are the show_extra columns
 * (docs/legacy/schema-sesf_dev.sql). price is double(10,2): sent as a decimal string and rounded by Money2.
 */
final class ExtraRequest extends FormRequest
{
    public const TEXT_MAX = 65535;

    public const COUNT_MAX = 4294967295;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:64', new Latin1Text],
            'description' => ['nullable', 'string', 'max:'.self::TEXT_MAX, new Latin1Text],
            'auth_key' => ['nullable', 'string', 'max:64', 'alpha_dash:ascii', Rule::unique('show_extra', 'auth_key')->ignore($this->route('extra'))],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'type_id' => ['required', Rule::enum(ExtraType::class)],
            'min_value' => ['required', 'integer', 'min:0', 'max:'.self::COUNT_MAX],
            'max_value' => ['required', 'integer', 'min:0', 'max:'.self::COUNT_MAX, 'gte:min_value'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
        if ($this->isMethod('POST')) {
            $rules['show_id'] = ['required', 'integer', 'exists:car_show,id'];
        }

        return $rules;
    }

    /**
     * Validated values. Money (price) is kept as the decimal string; the model's Money2 cast rounds it.
     * description is NOT NULL in the table, so a blank description is stored as ''.
     *
     * @return array{name: string, description: string, auth_key: ?string, price: string, type_id: int, min_value: int, max_value: int}
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $authKey = isset($data['auth_key']) ? trim((string) $data['auth_key']) : '';

        return [
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')),
            'auth_key' => $authKey === '' ? null : $authKey,
            'price' => (string) $data['price'],
            'type_id' => (int) $data['type_id'],
            'min_value' => (int) $data['min_value'],
            'max_value' => (int) $data['max_value'],
        ];
    }
}
