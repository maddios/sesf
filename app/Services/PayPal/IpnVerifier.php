<?php

namespace App\Services\PayPal;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Asks PayPal whether an IPN is genuine: posts "cmd=_notify-validate&" followed by the original
 * body, byte for byte, to config('sesf.paypal.verify_url') over verified TLS (10 s timeout).
 *
 * Returns true only for a reply body that is exactly "VERIFIED" (after trimming whitespace) and
 * false for exactly "INVALID". Anything else (HTTP error status, timeout, connection failure, an
 * unexpected body) throws RuntimeException: the message could not be verified either way.
 */
class IpnVerifier
{
    public const TIMEOUT_SECONDS = 10;

    public const USER_AGENT = 'SESF-IPN-Verifier/1.0';

    /**
     * @throws RuntimeException when PayPal's answer is neither VERIFIED nor INVALID.
     */
    public function verify(IpnPayload $payload): bool
    {
        $url = (string) config('sesf.paypal.verify_url');
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('sesf.paypal.verify_url must be an https URL.');
        }

        $body = 'cmd=_notify-validate'.($payload->rawBody === '' ? '' : '&'.$payload->rawBody);

        try {
            $response = Http::withOptions(['verify' => true])
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Connection' => 'close'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withBody($body, 'application/x-www-form-urlencoded')
                ->post($url);
        } catch (Throwable $e) {
            throw new RuntimeException('PayPal postback failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('PayPal postback returned HTTP '.$response->status().'.');
        }

        $reply = trim($response->body());

        return match ($reply) {
            'VERIFIED' => true,
            'INVALID' => false,
            default => throw new RuntimeException('PayPal postback returned an unexpected reply ('.strlen($reply).' bytes).'),
        };
    }
}
