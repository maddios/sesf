<?php

namespace App\Http\Requests\Account;

/**
 * POST member/message. Subject and body cannot contain CR or LF, so they cannot inject mail headers.
 */
class SendPrivateMessageRequest extends AccountFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:150', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
