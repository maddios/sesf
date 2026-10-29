<?php

namespace App\Rules;

use App\Services\Auth\TurnstileVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a Cloudflare Turnstile token submitted with a form (legacy 'turnstile' validator).
 *
 * The remote IP defaults to the current request's IP and is sent to Cloudflare as `remoteip`.
 */
class Turnstile implements ValidationRule
{
    public const MESSAGE = 'Please complete the security check before submitting.';

    public function __construct(private readonly ?string $remoteIp = null) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $token = is_string($value) ? $value : '';
        $ip = $this->remoteIp ?? request()->ip();

        if (! app(TurnstileVerifier::class)->verify($token, $ip)) {
            $fail(self::MESSAGE);
        }
    }
}
