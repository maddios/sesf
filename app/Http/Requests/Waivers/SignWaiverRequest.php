<?php

namespace App\Http\Requests\Waivers;

use App\Services\Waivers\SignPayload;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * POST waiver_sign/t/{trackingId} and waiver_sign/s/{showId}. Field names are the legacy widget's.
 * Widths follow the columns the values come from (user_guest.first/last 60, email 128). Failures
 * use the legacy shape: HTTP 400 {ok: false, error}.
 *
 * The signature (signature_base64, a PNG data URL in both modes) is only bounded here; its content
 * is checked and re-encoded by App\Services\Waivers\SignatureImage.
 */
class SignWaiverRequest extends FormRequest
{
    /** Names: any printable characters (no control or format characters). */
    private const NAME_PATTERN = '/\A[^\p{C}]+\z/u';

    /** Typed signature text: Latin letters, spaces, apostrophes, periods and hyphens. */
    private const TYPED_PATTERN = "/\\A[\\p{Latin} .'\\-]+\\z/u";

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['first_name', 'last_name', 'email', 'adult_first_name', 'adult_last_name', 'signature_text'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $trimmed[$key] = trim($value);
            }
        }
        $this->merge($trimmed);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxSignature = (int) config('sesf.waiver_signature_base64_max_chars', 400000) + 64;

        return [
            'first_name' => ['required', 'string', 'max:60', 'regex:'.self::NAME_PATTERN],
            'last_name' => ['required', 'string', 'max:60', 'regex:'.self::NAME_PATTERN],
            'email' => ['required', 'string', 'max:128', 'email:rfc'],
            'signature_mode' => ['nullable', 'in:draw,type'],
            'signature_base64' => ['required', 'string', 'max:'.$maxSignature],
            'signature_text' => ['nullable', 'string', 'max:'.(int) config('waivers.signature.typed_text_max_chars', 80), 'regex:'.self::TYPED_PATTERN],
            'signing_for_minor' => ['nullable', 'in:0,1,on,off,true,false'],
            'adult_first_name' => ['nullable', 'required_if:signing_for_minor,1,on,true', 'string', 'max:60', 'regex:'.self::NAME_PATTERN],
            'adult_last_name' => ['nullable', 'required_if:signing_for_minor,1,on,true', 'string', 'max:60', 'regex:'.self::NAME_PATTERN],
            'geolocation' => ['nullable', 'array:lat,lng,accuracy'],
            'geolocation.lat' => ['required_with:geolocation', 'numeric', 'between:-90,90'],
            'geolocation.lng' => ['required_with:geolocation', 'numeric', 'between:-180,180'],
            'geolocation.accuracy' => ['nullable', 'numeric', 'between:0,100000'],
            'waiver_ui_mode' => ['nullable', 'in:embedded,standalone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'First and last name are required.',
            'last_name.required' => 'First and last name are required.',
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'signature_base64.required' => 'Signature is required.',
            'signature_base64.max' => 'Signature image is too large.',
            'adult_first_name.required_if' => 'Parent or guardian first and last name are required when signing for a minor.',
            'adult_last_name.required_if' => 'Parent or guardian first and last name are required when signing for a minor.',
            'signature_text.regex' => 'The typed signature may only contain letters, spaces, apostrophes, periods and hyphens.',
        ];
    }

    public function toPayload(): SignPayload
    {
        $minor = in_array((string) $this->input('signing_for_minor'), ['1', 'on', 'true'], true);
        $geo = $this->validated('geolocation');

        return new SignPayload(
            firstName: (string) $this->validated('first_name'),
            lastName: (string) $this->validated('last_name'),
            email: (string) $this->validated('email'),
            mode: $this->validated('signature_mode') === 'type' ? 'type' : 'draw',
            signatureDataUrl: (string) $this->validated('signature_base64'),
            typedText: (string) ($this->validated('signature_text') ?? ''),
            isMinor: $minor,
            guardianFirst: $minor ? (string) $this->validated('adult_first_name') : '',
            guardianLast: $minor ? (string) $this->validated('adult_last_name') : '',
            geolocation: is_array($geo) ? [
                'lat' => (float) $geo['lat'],
                'lng' => (float) $geo['lng'],
                'accuracy' => isset($geo['accuracy']) ? (float) $geo['accuracy'] : null,
            ] : null,
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'error' => (string) $validator->errors()->first(),
        ], 400));
    }
}
