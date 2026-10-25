<?php

namespace App\Services\Auth;

/**
 * Vanilla jsConnect protocol v2 server side (legacy app/lib/jsconnect.php, writeJsConnect path).
 *
 * Pure functions: no globals, no output, no exit. The caller passes the current time, the request
 * parameters and the user identity, and gets back the body and content type to send.
 *
 * Protocol (as in the legacy library):
 *  - Request: v=2, client_id, timestamp (unix seconds), nonce, ip, sig = md5(ip.nonce.timestamp.secret).
 *    The timestamp must be within JS_TIMEOUT (24 minutes = 1440 seconds) of now, in either direction.
 *  - Signed-in response: the identity fields plus ip and nonce, lower-cased keys, ksort, nulls as '',
 *    http_build_query, then sig = md5(query.secret). The response adds client_id, sig and v=2.
 *  - Anonymous, or no signature sent: {"name":"","photourl":""} (unsigned). Vanilla reads this as signed out.
 *  - Errors: {"error": ..., "message": ...} (unsigned). The signature is md5 because the protocol says so;
 *    comparisons use hash_equals.
 *
 * Changes from the legacy code:
 *  - The optional callback is checked against CALLBACK_PATTERN. The legacy code echoed it raw (JSONP injection).
 *    A bad callback is an invalid_request error, returned as plain JSON.
 *  - JSONP responses use application/javascript. Other responses use application/json.
 *  - An empty client id or secret never matches (fails closed).
 *  - The legacy library printed nothing for some paths. This service always returns a body.
 */
final class JsConnect
{
    public const VERSION = '2';

    /** Allowed clock skew between the caller and this server, in seconds (legacy JS_TIMEOUT = 24 * 60). */
    public const TIMEOUT_SECONDS = 24 * 60;

    /** Accepted JSONP callback names (a JavaScript identifier, optionally dotted, at most 64 characters). */
    public const CALLBACK_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]{0,63}$/D';

    public const CONTENT_TYPE_JSON = 'application/json';

    public const CONTENT_TYPE_JAVASCRIPT = 'application/javascript';

    /**
     * Builds the jsConnect reply for one request.
     *
     * @param  array<string, mixed>  $user  Identity of the signed-in user. Empty when nobody is signed in.
     * @param  array<string, mixed>  $request  Query parameters of the request.
     * @return array{body: string, contentType: string}
     */
    public function respond(array $user, array $request, string $clientId, string $secret, int $now): array
    {
        $callback = null;

        if (array_key_exists('callback', $request)) {
            $callback = $this->stringParam($request, 'callback');

            if (preg_match(self::CALLBACK_PATTERN, $callback) !== 1) {
                return $this->plain($this->error('invalid_request', 'The callback parameter is invalid.'));
            }
        }

        $result = $this->result($user, $request, $clientId, $secret, $now);
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        if ($callback === null) {
            return ['body' => $json, 'contentType' => self::CONTENT_TYPE_JSON];
        }

        return ['body' => $callback.'('.$json.')', 'contentType' => self::CONTENT_TYPE_JAVASCRIPT];
    }

    /**
     * Signs the identity data (legacy signJsConnect). Returns the signed data with client_id and sig.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sign(array $data, string $clientId, string $secret): array
    {
        $normalized = $this->normalize($data);
        ksort($normalized);

        $signature = md5(http_build_query($normalized, '', '&').$secret);

        $normalized['client_id'] = $clientId;
        $normalized['sig'] = $signature;

        return $normalized;
    }

    /**
     * Computes the request signature md5(ip.nonce.timestamp.secret).
     */
    public function requestSignature(string $ip, string $nonce, string $timestamp, string $secret): string
    {
        return md5($ip.$nonce.$timestamp.$secret);
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function result(array $user, array $request, string $clientId, string $secret, int $now): array
    {
        $user = $this->normalize($user);

        if (! array_key_exists('v', $request)) {
            return $this->error('invalid_request', 'Missing the v parameter.');
        }

        if ($this->stringParam($request, 'v') !== self::VERSION) {
            return $this->error('invalid_request', 'Unsupported version '.$this->stringParam($request, 'v').'.');
        }

        if (! array_key_exists('client_id', $request)) {
            return $this->error('invalid_request', 'Missing the client_id parameter.');
        }

        $requestClientId = $this->stringParam($request, 'client_id');

        if ($clientId === '' || ! hash_equals($clientId, $requestClientId)) {
            return $this->error('invalid_client', 'Unknown client '.$requestClientId.'.');
        }

        if ($secret === '') {
            return $this->error('invalid_client', 'SSO is not configured.');
        }

        if (! array_key_exists('timestamp', $request) && ! array_key_exists('sig', $request)) {
            // Public information only, no signature. Legacy returned it for signed-in users.
            if ($user !== []) {
                return [
                    'name' => (string) ($user['name'] ?? ''),
                    'photourl' => $user['photourl'] ?? '',
                    'signedin' => true,
                ];
            }

            return ['name' => '', 'photourl' => ''];
        }

        $timestamp = $this->stringParam($request, 'timestamp');

        if (! array_key_exists('timestamp', $request) || preg_match('/^\d+$/', $timestamp) !== 1) {
            return $this->error('invalid_request', 'The timestamp parameter is missing or invalid.');
        }

        if (! array_key_exists('sig', $request)) {
            return $this->error('invalid_request', 'Missing the sig parameter.');
        }

        if (abs((int) $timestamp - $now) > self::TIMEOUT_SECONDS) {
            return $this->error('invalid_request', 'The timestamp is invalid.');
        }

        if (! array_key_exists('nonce', $request)) {
            return $this->error('invalid_request', 'Missing the nonce parameter.');
        }

        if (! array_key_exists('ip', $request)) {
            return $this->error('invalid_request', 'Missing the ip parameter.');
        }

        $ip = $this->stringParam($request, 'ip');
        $nonce = $this->stringParam($request, 'nonce');
        $expected = $this->requestSignature($ip, $nonce, $timestamp, $secret);

        if (! hash_equals($expected, $this->stringParam($request, 'sig'))) {
            return $this->error('access_denied', 'Signature invalid.');
        }

        if ($user === []) {
            return ['name' => '', 'photourl' => ''];
        }

        $user['ip'] = $ip;
        $user['nonce'] = $nonce;

        $result = $this->sign($user, $clientId, $secret);
        $result['v'] = self::VERSION;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $error, string $message): array
    {
        return ['error' => $error, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{body: string, contentType: string}
     */
    private function plain(array $result): array
    {
        return ['body' => json_encode($result, JSON_THROW_ON_ERROR), 'contentType' => self::CONTENT_TYPE_JSON];
    }

    /**
     * Lower-cases the keys, turns null into '' and returns the scalars as strings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[strtolower((string) $key)] = $value ?? '';
        }

        return $normalized;
    }

    /**
     * A query parameter as a string. A non-string value (for example an array from ?v[]=2) becomes ''
     * and fails the checks that follow.
     *
     * @param  array<string, mixed>  $request
     */
    private function stringParam(array $request, string $key): string
    {
        $value = $request[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
