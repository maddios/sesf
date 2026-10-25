<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * POST login/update-password, for the signed-in member only. Legacy failure shapes are kept:
 * 401 for anonymous callers, 400 with a JSON array of message strings otherwise.
 *
 * The member is always Auth::user(); any user_id in the request is ignored (legacy IDOR).
 *
 * @property-read string $old_password
 * @property-read string $new_password
 * @property-read string $verify_password
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'old_password' => ['required', 'string', 'max:1024'],
            'new_password' => ['required', 'string', 'min:6', 'max:1024'],
            'verify_password' => ['required', 'same:new_password'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json(['Not authenticated'], 401));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json($validator->errors()->all(), 400));
    }
}
