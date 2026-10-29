<?php

namespace App\Http\Requests\Account;

/**
 * POST tutorial/tutorial. Sets the signed-in member's own welcome-tour flag.
 */
class UpdateTutorialRequest extends AccountFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'show_login_welcome' => ['required', 'boolean'],
        ];
    }
}
