<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use App\Rules\Latin1Text;
use App\Support\EmailAddress;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * POST member/account-info. Changing the email requires the current password (new behaviour;
 * the legacy code changed the email with no check). The password is only needed when the
 * normalised address actually differs from the stored one.
 */
class UpdateAccountInfoRequest extends AccountFormRequest
{
    public function emailChanged(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && EmailAddress::normalize($this->input('email')) !== EmailAddress::normalize($user->email);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            // Widths are the user table columns (docs/legacy/schema-sesf_dev.sql); see RequestWidthsTest.
            'first' => ['required', 'string', 'regex:/^[a-zA-Z0-9.\- ]+$/', 'max:20', new Latin1Text],
            'last' => ['required', 'string', 'regex:/^[a-zA-Z0-9.\- ]+$/', 'max:20', new Latin1Text],
            'email' => ['required', 'string', 'email', 'max:60', new Latin1Text, Rule::unique(User::class, 'email')->ignore($userId)],
            'phone' => ['required', 'string', 'regex:/^[0-9]{3}-?[0-9]{3}-?[0-9]{4}$/', 'max:15'],
            'current_password' => [Rule::requiredIf(fn (): bool => $this->emailChanged()), 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $this->emailChanged() || ! $user instanceof User || ! $this->filled('current_password')) {
                    return;
                }

                if (! Hash::check((string) $this->input('current_password'), $user->password)) {
                    $validator->errors()->add('current_password', 'Current password is incorrect.');
                }
            },
        ];
    }
}
