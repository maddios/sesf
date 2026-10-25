<?php

namespace App\Http\Requests\Account;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for the member and contact endpoints. Legacy failure shape: HTTP 400 with a JSON array of
 * message strings (the Vue app reads this shape). The routes behind these requests are either
 * public (contact, register) or behind the auth middleware, so authorize() is always true here.
 */
abstract class AccountFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json($validator->errors()->all(), 400));
    }
}
