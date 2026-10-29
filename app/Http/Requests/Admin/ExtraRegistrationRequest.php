<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT admin-api/extra-registrations/{id}: the quantity (0 removes the purchase) and optionally another extra of the
 * same event (show_extra_id). Widths are show_extra_registration.num_extras
 * (smallint unsigned).
 */
final class ExtraRegistrationRequest extends FormRequest
{
    public const COUNT_MAX = 65535;

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
            'num_extras' => ['required', 'integer', 'min:0', 'max:'.self::COUNT_MAX],
            'show_extra_id' => ['sometimes', 'integer', 'exists:show_extra,id'],
        ];
    }

    /**
     * @return array{num_extras: int}
     */
    public function fields(): array
    {
        return ['num_extras' => (int) $this->validated('num_extras')];
    }

    /** The extra the purchase changes to (legacy showExtra relation), or null to keep it. */
    public function extraId(): ?int
    {
        return $this->filled('show_extra_id') ? $this->integer('show_extra_id') : null;
    }
}
