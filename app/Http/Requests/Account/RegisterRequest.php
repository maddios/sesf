<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use App\Rules\Latin1Text;
use App\Rules\Turnstile;
use Illuminate\Validation\Rule;

/**
 * POST member/register (legacy MemberProfile::postRegister rules).
 *
 * The duplicate-email message is the legacy generic text. The legacy "name match" lookup that
 * revealed a masked email address is removed.
 */
class RegisterRequest extends AccountFormRequest
{
    /** Legacy text for a duplicate email; it does not say whether the account was deleted. */
    public const DUPLICATE_MESSAGE = 'Looks like you have already registered. Please click LOGIN at the top.';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Widths are the user table columns (docs/legacy/schema-sesf_dev.sql); see RequestWidthsTest.
            'first_name' => ['required', 'string', 'regex:/^[a-zA-Z0-9.\- ]+$/', 'max:20', new Latin1Text],
            'last_name' => ['required', 'string', 'regex:/^[a-zA-Z0-9.\- ]+$/', 'max:20', new Latin1Text],
            'email' => ['required', 'string', 'email', 'max:60', new Latin1Text, 'confirmed', Rule::unique(User::class, 'email')],
            'street' => ['required', 'string', 'max:60', new Latin1Text],
            'street2' => ['nullable', 'string', 'max:60', new Latin1Text],
            'city' => ['required', 'string', 'max:60', new Latin1Text],
            'state' => ['required', 'string', 'max:10', new Latin1Text],
            'zip' => ['required', 'string', 'regex:/^[0-9a-zA-Z\-]+$/', 'max:10'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{3}-?[0-9]{3}-?[0-9]{4}$/', 'max:15'],
            'accept_privacy_policy' => ['accepted'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'cf_turnstile_token' => ['required', new Turnstile],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_privacy_policy.accepted' => 'You must accept the Privacy Policy to complete your registration.',
            'email.unique' => self::DUPLICATE_MESSAGE,
            'cf_turnstile_token.required' => 'Please complete the security check before submitting.',
        ];
    }
}
