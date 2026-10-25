<?php

namespace App\Http\Requests\Account;

/**
 * POST home/report (contact form). Anonymous visitors give a name and email; signed-in members
 * are identified from their session. No Turnstile (not in the legacy form); the route is rate
 * limited instead. Header-injection characters are rejected in every line-based field.
 */
class ContactReportRequest extends AccountFormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [
            'subject' => ['required', 'string', 'max:150', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:500', 'not_regex:/[\r\n]/'],
        ];

        if ($this->user() === null) {
            $rules['name'] = ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'];
            $rules['email'] = ['required', 'string', 'email', 'max:254', 'not_regex:/[\r\n]/'];
        }

        return $rules;
    }
}
