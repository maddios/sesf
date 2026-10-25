<?php

namespace App\Http\Requests\Account;

/**
 * POST member/delete-account. The member must confirm with their current password.
 */
class DeleteAccountRequest extends AccountFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
