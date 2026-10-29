<?php

namespace App\Http\Requests\Admin;

use App\Enums\AgendaType;
use App\Rules\Latin1Text;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST admin-api/agenda (with show_id) and PUT admin-api/agenda/{id}. The event (show_id) is set on create only:
 * an item stays with its event, and its position is managed by the reorder endpoint.
 * Widths are the show_agenda columns (docs/legacy/schema-sesf_dev.sql); the columns are latin1.
 */
final class AgendaRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', new Latin1Text],
            'text' => ['nullable', 'string', 'max:'.self::TEXT_MAX, new Latin1Text],
            'type_id' => ['required', Rule::enum(AgendaType::class)],
            'is_visible' => ['sometimes', 'boolean'],
        ];
        if ($this->isMethod('POST')) {
            $rules['show_id'] = ['required', 'integer', 'exists:car_show,id'];
        }

        return $rules;
    }

    /**
     * Validated values for the model. Blank strings are stored as NULL. The HTML is cleaned with the
     * public allow-list (App\Support\HtmlSanitizer) before it is stored.
     *
     * @return array{name: string, text: ?string, type_id: int}
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $text = app(HtmlSanitizer::class)->sanitize(isset($data['text']) ? (string) $data['text'] : null);

        return [
            'name' => trim((string) $data['name']),
            'text' => trim($text) === '' ? null : $text,
            'type_id' => (int) $data['type_id'],
        ];
    }
}
