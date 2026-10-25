<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\EmailSigninService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Checks the emailed six-digit code. Spaces are stripped first. Every validation failure gets the
 * same 401 body as a wrong code, so the response does not show which check failed.
 */
class EmailSigninVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        $this->merge([
            'code' => is_string($code) ? str_replace(' ', '', $code) : $code,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            new JsonResponse([EmailSigninService::INVALID_MESSAGE], 401),
        );
    }
}
