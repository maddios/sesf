<?php

namespace App\Http\Requests\Admin;

use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/sponsors and PUT admin-api/sponsors/{id}: the full editable field set.
 * Access is enforced by the admin route group, so authorize() only re-checks the role.
 * Widths are the sponsor table columns (docs/legacy/schema-sesf_dev.sql); the columns are latin1.
 * Errors are Laravel's standard 422 {message, errors}.
 */
final class SponsorRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255', new Latin1Text],
            'URL' => ['nullable', 'string', 'max:255', 'url:http,https', new Latin1Text],
            'description' => ['nullable', 'string', 'max:'.self::TEXT_MAX, new Latin1Text],
            'short_description' => ['nullable', 'string', 'max:512', new Latin1Text],
            'internal_notes' => ['nullable', 'string', 'max:'.self::TEXT_MAX, new Latin1Text],
            'is_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Validated values ready for the model. Blank strings are stored as NULL (Laravel's
     * ConvertEmptyStringsToNull middleware already does this for the request).
     *
     * @return array{name: string, URL: ?string, description: ?string, short_description: ?string, internal_notes: ?string}
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'name' => trim((string) $data['name']),
            'URL' => self::nullableText($data['URL'] ?? null),
            'description' => self::nullableText($data['description'] ?? null),
            'short_description' => self::nullableText($data['short_description'] ?? null),
            'internal_notes' => self::nullableText($data['internal_notes'] ?? null),
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
