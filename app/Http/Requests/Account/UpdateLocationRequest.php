<?php

namespace App\Http\Requests\Account;

use App\Rules\Latin1Text;

/**
 * POST member/location. Always applies to the signed-in member; a user_id input is ignored.
 */
class UpdateLocationRequest extends AccountFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Widths are the user table columns (docs/legacy/schema-sesf_dev.sql); see RequestWidthsTest.
            'address1' => ['required', 'string', 'min:2', 'max:60', new Latin1Text],
            'address2' => ['nullable', 'string', 'max:60', new Latin1Text],
            'city' => ['required', 'string', 'max:60', new Latin1Text],
            'state' => ['required', 'string', 'alpha', 'size:2'],
            // Required: the legacy rule has no "required", but an empty value failed alpha_num anyway.
            'zip' => ['required', 'string', 'alpha_num', 'min:5', 'max:10'],
        ];
    }
}
