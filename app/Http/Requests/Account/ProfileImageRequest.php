<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * POST member/profile-image. Only the signed-in member can upload, for their own account. A
 * user_id field in the request is ignored (the legacy code trusted it).
 *
 * Errors use the legacy shapes: 401 with a message array when signed out, and 400 with a flat
 * array of messages when the upload is wrong.
 */
final class ProfileImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:12288'],
        ];
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json(['Auth error, not permitted'], 401));
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json(array_values($validator->errors()->all()), 400));
    }
}
