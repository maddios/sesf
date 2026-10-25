<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side check of a Cloudflare Turnstile token (legacy services/TurnstileVerifier.php).
 *
 * A token is single-use and expires about five minutes after it is issued. Cloudflare returns
 * `success: true` only for a valid, unused token.
 *
 * Fails closed: a missing secret, an empty token, a network error or timeout, a non-JSON body,
 * or any response without `success === true` returns false. Logs never contain the token or the
 * secret; only Cloudflare's error codes are logged.
 */
class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const TIMEOUT_SECONDS = 5;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    public function verify(string $token, ?string $ip): bool
    {
        $secret = (string) config('sesf.turnstile.secret');

        if ($secret === '') {
            Log::warning('Turnstile: secret not configured; rejecting submission.');

            return false;
        }

        if (trim($token) === '') {
            return false;
        }

        $payload = ['secret' => $secret, 'response' => $token];

        if ($ip !== null && $ip !== '') {
            $payload['remoteip'] = $ip;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->asForm()
                ->acceptJson()
                ->post(self::VERIFY_URL, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Turnstile: verify network error ('.$e::class.').');

            return false;
        } catch (Throwable $e) {
            Log::warning('Turnstile: verify request failed ('.$e::class.').');

            return false;
        }

        $data = json_decode($response->body(), true);

        if ($response->failed() || ! is_array($data) || ($data['success'] ?? false) !== true) {
            $codes = is_array($data) && isset($data['error-codes']) && is_array($data['error-codes'])
                ? implode(',', array_map(strval(...), $data['error-codes']))
                : 'unknown';

            Log::info('Turnstile: verify rejected: '.$codes);

            return false;
        }

        return true;
    }
}
