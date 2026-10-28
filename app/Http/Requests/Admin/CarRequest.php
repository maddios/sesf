<?php

namespace App\Http\Requests\Admin;

use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/cars and PUT admin-api/cars/{id}: the editable car fields. Widths are the car table columns
 * (docs/legacy/schema-sesf_dev.sql). user_id is guarded on the model and is written with forceFill().
 * is_confirmed is not editable here: it has its own confirm/unconfirm actions.
 */
final class CarRequest extends FormRequest
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
            'user_id' => ['nullable', 'integer', 'exists:user,id'],
            'type_id' => ['required', 'integer', 'exists:car_type,id'],
            'body_id' => ['required', 'integer', 'exists:car_body,id'],
            'year' => ['nullable', 'string', 'max:5', new Latin1Text],
            'model' => ['nullable', 'string', 'max:30', new Latin1Text],
            'nickname' => ['nullable', 'string', 'max:64', new Latin1Text],
            'VIN' => ['nullable', 'string', 'max:20', new Latin1Text],
        ];
    }

    /**
     * @return array{user_id: ?int, type_id: int, body_id: int, year: ?string, model: ?string, nickname: ?string, VIN: ?string}
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'type_id' => (int) $data['type_id'],
            'body_id' => (int) $data['body_id'],
            'year' => self::nullableText($data['year'] ?? null),
            'model' => self::nullableText($data['model'] ?? null),
            'nickname' => self::nullableText($data['nickname'] ?? null),
            'VIN' => self::nullableText($data['VIN'] ?? null),
        ];
    }

    private static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
