<?php

namespace App\Http\Requests\Admin;

use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/locations (with show_id) and PUT admin-api/locations/{id}. The event is set on create only.
 * Coordinates are not editable here (the legacy geocoder is not ported). Widths are the show_location columns
 * (docs/legacy/schema-sesf_dev.sql); the columns are latin1.
 */
final class LocationRequest extends FormRequest
{
    public const TEXT_MAX = 65535;

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
            'name' => ['required', 'string', 'max:128', new Latin1Text],
            'description' => ['nullable', 'string', 'max:'.self::TEXT_MAX, new Latin1Text],
            'address1' => ['required', 'string', 'max:60', new Latin1Text],
            'address2' => ['nullable', 'string', 'max:60', new Latin1Text],
            'city' => ['required', 'string', 'max:60', new Latin1Text],
            'state' => ['required', 'string', 'max:10', new Latin1Text],
            'zip' => ['required', 'string', 'max:10', new Latin1Text],
            'is_primary' => ['sometimes', 'boolean'],
        ];
        if ($this->isMethod('POST')) {
            $rules['show_id'] = ['required', 'integer', 'exists:car_show,id'];
        }

        return $rules;
    }

    /**
     * Validated values for the model. Blank optional text is stored as NULL.
     *
     * @return array{name: string, description: ?string, address1: string, address2: ?string, city: string, state: string, zip: string}
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'name' => trim((string) $data['name']),
            'description' => self::nullableText($data['description'] ?? null),
            'address1' => trim((string) $data['address1']),
            'address2' => self::nullableText($data['address2'] ?? null),
            'city' => trim((string) $data['city']),
            'state' => trim((string) $data['state']),
            'zip' => trim((string) $data['zip']),
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
