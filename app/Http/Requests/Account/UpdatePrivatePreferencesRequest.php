<?php

namespace App\Http\Requests\Account;

/**
 * POST member/private-preferences. Only the three legacy flags; the row is always the
 * signed-in member's own (the legacy id input is ignored).
 */
class UpdatePrivatePreferencesRequest extends AccountFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'allow_system_email' => ['required', 'boolean'],
            'allow_future_show_notifications' => ['required', 'boolean'],
            'allow_private_message' => ['required', 'boolean'],
        ];
    }
}
