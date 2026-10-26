<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserType;
use App\Rules\Latin1Text;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT admin-api/users/{id}: the editable member fields. Passwords are not editable here: a reset goes
 * through the reset mail (UserController::sendPasswordReset). Widths are the user table columns
 * (docs/legacy/schema-sesf_dev.sql). type_id is guarded on the model and is written with forceFill().
 */
final class UserRequest extends FormRequest
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
        $userId = (int) $this->route('user');

        return [
            'first' => ['nullable', 'string', 'max:20', new Latin1Text],
            'last' => ['nullable', 'string', 'max:20', new Latin1Text],
            'email' => ['required', 'string', 'email', 'max:60', Rule::unique('user', 'email')->ignore($userId), new Latin1Text],
            'phone' => ['nullable', 'string', 'max:15', new Latin1Text],
            'phone2' => ['nullable', 'string', 'max:15', new Latin1Text],
            'heard_from' => ['nullable', 'string', 'max:500', new Latin1Text],
            'address1' => ['required', 'string', 'max:60', new Latin1Text],
            'address2' => ['nullable', 'string', 'max:60', new Latin1Text],
            'city' => ['required', 'string', 'max:60', new Latin1Text],
            'state' => ['required', 'string', 'max:10', new Latin1Text],
            'zip' => ['required', 'string', 'max:10', new Latin1Text],
            'type_id' => ['sometimes', 'integer', Rule::enum(UserType::class)],
        ];
    }

    /**
     * Validated values ready for the model. Blank optional strings are NULL; the NOT NULL address
     * columns are stored as the empty string (they are required above, so this only trims).
     *
     * @return array<string, ?string>
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $fields = [];
        foreach (['first', 'last', 'phone', 'phone2', 'heard_from', 'address2'] as $key) {
            $fields[$key] = self::nullableText($data[$key] ?? null);
        }
        foreach (['email', 'address1', 'city', 'state', 'zip'] as $key) {
            $fields[$key] = trim((string) $data[$key]);
        }

        return $fields;
    }

    /** The requested account type, or null when the field was not sent. */
    public function typeId(): ?UserType
    {
        return $this->has('type_id') ? UserType::from((int) $this->validated('type_id')) : null;
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
