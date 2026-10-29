<?php

namespace App\Http\Requests\Admin;

use App\Models\CarShow;
use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST admin-api/events and PUT admin-api/events/{id}: the editable event fields (car_show).
 * Widths are the car_show columns (docs/legacy/schema-sesf_dev.sql); text is latin1.
 * Creating an event requires clone_from_show_id once any event exists (legacy carShow.php rule).
 * Activation and judging release are not editable here (guarded; see the activate routes and admin-api/judging/shows/{id}/release).
 * waiver_id is the legacy waiver switch: non-empty turns the internal waiver on for the event.
 */
final class EventRequest extends FormRequest
{
    public const TEXT_MAX = 65535;

    public const DESCRIPTION_MAX = 1000;

    public const SHORT_DESCRIPTION_MAX = 1024;

    /**
     * Rich text fields (HTML from the admin editor) => [label in the too-long message, column width].
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const RICH_TEXT_FIELDS = [
        'short_description' => ['short description', self::SHORT_DESCRIPTION_MAX],
        'description' => ['description', self::DESCRIPTION_MAX],
        'schedule' => ['schedule', self::TEXT_MAX],
    ];

    public const MONEY_MAX = 9999.99;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $eventId = $this->route('event');

        $rules = [
            'name' => ['required', 'string', 'max:60', new Latin1Text],
            'short_name' => ['required', 'string', 'max:20', new Latin1Text, Rule::unique('car_show', 'short_name')->ignore($eventId)],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX, new Latin1Text],
            'short_description' => ['nullable', 'string', 'max:'.self::SHORT_DESCRIPTION_MAX, new Latin1Text],
            'schedule' => ['nullable', 'string', 'max:'.self::TEXT_MAX, new Latin1Text],
            'is_schedule_pdf' => ['sometimes', 'boolean'],
            'price_car' => ['nullable', 'numeric', 'min:0', 'max:'.self::MONEY_MAX],
            'price_adult' => ['nullable', 'numeric', 'min:0', 'max:'.self::MONEY_MAX],
            'price_child' => ['nullable', 'numeric', 'min:0', 'max:'.self::MONEY_MAX],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'date_active' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'date_registration_deactivation' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'waiver_id' => ['nullable', 'string', 'max:32', new Latin1Text],
        ];

        if ($this->isMethod('POST')) {
            $rules['clone_from_show_id'] = [Rule::requiredIf(fn () => CarShow::query()->exists()), 'integer', 'exists:car_show,id'];
        }

        return $rules;
    }

    /**
     * The rich text fields are stored as HTML, so the limit counts the markup too. The message says so and gives the
     * current length; nothing is ever cut to fit.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (self::RICH_TEXT_FIELDS as $field => [$label, $max]) {
            $value = $this->input($field);
            $length = is_string($value) ? mb_strlen($value) : 0;
            $messages[$field.'.max'] = sprintf(
                'The %s is too long: %s of %s characters, counting its formatting (HTML). Remove some text or formatting.',
                $label,
                number_format($length),
                number_format($max),
            );
        }

        return $messages;
    }

    /**
     * Validated values for the model (blank text is stored as NULL, money as a decimal string).
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'name' => trim((string) $data['name']),
            'short_name' => trim((string) $data['short_name']),
            'description' => self::nullableText($data['description'] ?? null),
            'short_description' => self::nullableText($data['short_description'] ?? null),
            'schedule' => self::nullableText($data['schedule'] ?? null),
            'is_schedule_pdf' => $this->boolean('is_schedule_pdf'),
            'price_car' => self::money($data['price_car'] ?? null),
            'price_adult' => self::money($data['price_adult'] ?? null),
            'price_child' => self::money($data['price_child'] ?? null),
            'date_start' => $data['date_start'] ?? null,
            'date_end' => $data['date_end'] ?? null,
            'date_active' => $data['date_active'] ?? null,
            'date_registration_deactivation' => $data['date_registration_deactivation'] ?? null,
            'waiver_id' => self::nullableText($data['waiver_id'] ?? null),
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

    private static function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
