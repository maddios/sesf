<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin-api/users/{id}/password {password, password_confirmation}: an administrator sets a member's password
 * (legacy frozennode user.php password + confirm_password). Same rules as registration.
 */
final class UserPasswordRequest extends FormRequest
{
    public const PASSWORD_RULES = ['required', 'string', 'min:6', 'confirmed'];

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['password' => self::PASSWORD_RULES];
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }
}
