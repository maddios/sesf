<?php

use App\Services\Auth\JsConnect;

const JSC_CLIENT = 'sesfclient';
const JSC_SECRET = 'sesfsecret';
const JSC_NOW = 1800000000;
const JSC_IP = '203.0.113.9';
const JSC_NONCE = 'abc123';

/**
 * The identity the legacy vanilla() method produced for user 42.
 *
 * @return array<string, mixed>
 */
function jscUser(): array
{
    return [
        'uniqueid' => 42,
        'name' => 'Jane_D_42',
        'email' => 'jane@example.com',
        'photourl' => 'https://sesf.test/images/members/42/profile.jpg?1',
        'roles' => 'member,administrator',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jscRequest(array $overrides = []): array
{
    $timestamp = $overrides['timestamp'] ?? (string) JSC_NOW;
    $ip = $overrides['ip'] ?? JSC_IP;
    $nonce = $overrides['nonce'] ?? JSC_NONCE;

    $request = [
        'v' => '2',
        'client_id' => JSC_CLIENT,
        'timestamp' => $timestamp,
        'nonce' => $nonce,
        'ip' => $ip,
        'sig' => md5($ip.$nonce.$timestamp.JSC_SECRET),
    ];

    unset($overrides['timestamp'], $overrides['ip'], $overrides['nonce']);

    return array_merge($request, $overrides);
}

/**
 * @param  array<string, mixed>  $user
 * @param  array<string, mixed>  $request
 * @return array{body: string, contentType: string}
 */
function jscRespond(array $user, array $request, int $now = JSC_NOW, string $client = JSC_CLIENT, string $secret = JSC_SECRET): array
{
    return app(JsConnect::class)->respond($user, $request, $client, $secret, $now);
}

/** @return array<string, mixed> */
function jscDecode(string $body): array
{
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

test('signed-in reply matches the legacy library byte for byte', function () {
    $reply = jscRespond(jscUser(), jscRequest());

    expect($reply['body'])->toBe('{"email":"jane@example.com","ip":"203.0.113.9","name":"Jane_D_42","nonce":"abc123","photourl":"https:\/\/sesf.test\/images\/members\/42\/profile.jpg?1","roles":"member,administrator","uniqueid":42,"client_id":"sesfclient","sig":"7ca0d8f13040f5778e1a11e61d87676e","v":"2"}');
    expect($reply['contentType'])->toBe('application/json');
});

test('the reply signature verifies with md5 of the sorted query and the secret', function () {
    $body = jscDecode(jscRespond(jscUser(), jscRequest())['body']);

    $signed = $body;
    unset($signed['client_id'], $signed['sig'], $signed['v']);
    ksort($signed);

    expect($body['sig'])->toBe(md5(http_build_query($signed, '', '&').JSC_SECRET));
    expect($body['v'])->toBe('2');
    expect($body['client_id'])->toBe(JSC_CLIENT);
});

test('a reply signature is not accepted with a different secret', function () {
    $body = jscDecode(jscRespond(jscUser(), jscRequest())['body']);

    $signed = $body;
    unset($signed['client_id'], $signed['sig'], $signed['v']);
    ksort($signed);

    expect($body['sig'])->not->toBe(md5(http_build_query($signed, '', '&').'another-secret'));
});

test('a timestamp exactly 24 minutes old is accepted', function () {
    $ts = JSC_NOW - JsConnect::TIMEOUT_SECONDS;

    expect(jscDecode(jscRespond(jscUser(), jscRequest(['timestamp' => (string) $ts]), JSC_NOW)['body']))->toHaveKey('sig');
});

test('a timestamp exactly 24 minutes in the future is accepted', function () {
    $ts = JSC_NOW + JsConnect::TIMEOUT_SECONDS;

    expect(jscDecode(jscRespond(jscUser(), jscRequest(['timestamp' => (string) $ts]), JSC_NOW)['body']))->toHaveKey('sig');
});

test('a timestamp one second too old is rejected', function () {
    $ts = JSC_NOW - JsConnect::TIMEOUT_SECONDS - 1;

    expect(jscDecode(jscRespond(jscUser(), jscRequest(['timestamp' => (string) $ts]), JSC_NOW)['body']))
        ->toBe(['error' => 'invalid_request', 'message' => 'The timestamp is invalid.']);
});

test('a timestamp one second in the future is rejected', function () {
    $ts = JSC_NOW + JsConnect::TIMEOUT_SECONDS + 1;

    expect(jscDecode(jscRespond(jscUser(), jscRequest(['timestamp' => (string) $ts]), JSC_NOW)['body']))
        ->toBe(['error' => 'invalid_request', 'message' => 'The timestamp is invalid.']);
});

test('the legacy expired timestamp reply matches', function () {
    $ts = JSC_NOW - 1441;

    expect(jscRespond(jscUser(), jscRequest(['timestamp' => (string) $ts]), JSC_NOW)['body'])
        ->toBe('{"error":"invalid_request","message":"The timestamp is invalid."}');
});

test('a bad signature is denied', function () {
    $request = jscRequest(['sig' => 'deadbeef']);

    expect(jscRespond(jscUser(), $request)['body'])
        ->toBe('{"error":"access_denied","message":"Signature invalid."}');
});

test('a signature made with another secret is denied', function () {
    $request = jscRequest();
    $request['sig'] = md5(JSC_IP.JSC_NONCE.JSC_NOW.'other-secret');

    expect(jscDecode(jscRespond(jscUser(), $request)['body']))->toBe(['error' => 'access_denied', 'message' => 'Signature invalid.']);
});

test('a wrong client id is rejected as an unknown client', function () {
    $request = jscRequest(['client_id' => 'other']);

    expect(jscRespond(jscUser(), $request)['body'])
        ->toBe('{"error":"invalid_client","message":"Unknown client other."}');
});

test('an unconfigured (empty) client id never matches', function () {
    $request = jscRequest(['client_id' => '']);

    expect(jscDecode(jscRespond(jscUser(), $request, JSC_NOW, '')['body']))
        ->toBe(['error' => 'invalid_client', 'message' => 'Unknown client .']);
});

test('an unconfigured (empty) secret is refused and never signs', function () {
    $reply = jscRespond(jscUser(), jscRequest(), JSC_NOW, JSC_CLIENT, '');

    expect(jscDecode($reply['body']))->toBe(['error' => 'invalid_client', 'message' => 'SSO is not configured.']);
    expect($reply['body'])->not->toContain('sig');
});

test('a missing or unsupported version is rejected', function () {
    $missing = jscRequest();
    unset($missing['v']);

    expect(jscRespond(jscUser(), $missing)['body'])->toBe('{"error":"invalid_request","message":"Missing the v parameter."}');
    expect(jscRespond(jscUser(), jscRequest(['v' => '3']))['body'])->toBe('{"error":"invalid_request","message":"Unsupported version 3."}');
});

test('a missing client id is rejected', function () {
    $request = jscRequest();
    unset($request['client_id']);

    expect(jscDecode(jscRespond(jscUser(), $request)['body']))
        ->toBe(['error' => 'invalid_request', 'message' => 'Missing the client_id parameter.']);
});

test('a non-numeric timestamp is rejected', function () {
    expect(jscDecode(jscRespond(jscUser(), jscRequest(['timestamp' => '1800000000.5']))['body']))
        ->toBe(['error' => 'invalid_request', 'message' => 'The timestamp parameter is missing or invalid.']);
    expect(jscDecode(jscRespond(jscUser(), jscRequest(['timestamp' => '-5']))['body']))
        ->toBe(['error' => 'invalid_request', 'message' => 'The timestamp parameter is missing or invalid.']);
});

test('a missing nonce or ip is rejected', function () {
    $noNonce = jscRequest();
    unset($noNonce['nonce']);
    $noIp = jscRequest();
    unset($noIp['ip']);

    expect(jscDecode(jscRespond(jscUser(), $noNonce)['body']))->toBe(['error' => 'invalid_request', 'message' => 'Missing the nonce parameter.']);
    expect(jscDecode(jscRespond(jscUser(), $noIp)['body']))->toBe(['error' => 'invalid_request', 'message' => 'Missing the ip parameter.']);
});

test('an array value for a parameter is treated as invalid, not as a crash', function () {
    $request = jscRequest(['client_id' => ['sesfclient']]);

    expect(jscDecode(jscRespond(jscUser(), $request)['body']))->toHaveKey('error');
});

test('an anonymous visitor with a valid signature gets the empty-user reply', function () {
    $reply = jscRespond([], jscRequest());

    expect($reply['body'])->toBe('{"name":"","photourl":""}');
    expect($reply['contentType'])->toBe('application/json');
});

test('an anonymous visitor with no signature gets the empty-user reply', function () {
    expect(jscRespond([], ['v' => '2', 'client_id' => JSC_CLIENT])['body'])->toBe('{"name":"","photourl":""}');
});

test('a signed-in user with no signature gets the public name and photo only', function () {
    expect(jscRespond(jscUser(), ['v' => '2', 'client_id' => JSC_CLIENT])['body'])
        ->toBe('{"name":"Jane_D_42","photourl":"https:\/\/sesf.test\/images\/members\/42\/profile.jpg?1","signedin":true}');
});

test('the JSONP reply wraps the body in the callback with a javascript content type', function () {
    $reply = jscRespond(jscUser(), jscRequest(['callback' => 'jQuery123_cb']));

    expect($reply['body'])->toStartWith('jQuery123_cb({"email":"jane@example.com"');
    expect($reply['body'])->toEndWith('"v":"2"})');
    expect($reply['contentType'])->toBe('application/javascript');
});

test('the legacy callback injection payload is refused and not echoed', function () {
    $reply = jscRespond(jscUser(), jscRequest(['callback' => 'alert(1);x']));

    expect($reply['contentType'])->toBe('application/json');
    expect($reply['body'])->toBe('{"error":"invalid_request","message":"The callback parameter is invalid."}');
    expect($reply['body'])->not->toContain('alert');
});

test('the callback must match the identifier pattern', function (string $callback) {
    $reply = jscRespond([], jscRequest(['callback' => $callback]));

    expect(jscDecode($reply['body']))->toBe(['error' => 'invalid_request', 'message' => 'The callback parameter is invalid.']);
    expect($reply['contentType'])->toBe('application/json');
})->with([
    '<script>' => ['<script>'],
    'parens' => ['cb()'],
    'space' => ['a b'],
    'starts with digit' => ['1cb'],
    'empty' => [''],
    'too long (65)' => [str_repeat('a', 65)],
    'quote' => ['cb"'],
    'newline' => ["cb\n"],
    'slash' => ['cb/x'],
]);

test('callback names with dots or 64 characters are accepted', function () {
    expect(jscRespond([], jscRequest(['callback' => 'a.b_9']))['contentType'])->toBe('application/javascript');
    expect(jscRespond([], jscRequest(['callback' => str_repeat('a', 64)]))['contentType'])->toBe('application/javascript');
});

test('a non-string callback (array) is refused', function () {
    $reply = jscRespond([], jscRequest(['callback' => ['x']]));

    expect($reply['contentType'])->toBe('application/json');
    expect($reply['body'])->toContain('invalid_request');
});

test('the response never contains the secret', function () {
    $cases = [
        jscRespond(jscUser(), jscRequest()),
        jscRespond(jscUser(), jscRequest(['sig' => 'x'])),
        jscRespond([], jscRequest()),
        jscRespond(jscUser(), ['v' => '2', 'client_id' => JSC_CLIENT]),
        jscRespond(jscUser(), jscRequest(['callback' => 'cb'])),
    ];

    foreach ($cases as $reply) {
        expect($reply['body'])->not->toContain(JSC_SECRET);
    }
});

test('sign() lower-cases keys, turns null into empty strings and adds client_id and sig', function () {
    $signed = app(JsConnect::class)->sign(['Name' => 'Jane', 'Note' => null, 'UniqueID' => 7], 'client', 'secret');

    expect(array_keys($signed))->toBe(['name', 'note', 'uniqueid', 'client_id', 'sig']);
    expect($signed['note'])->toBe('');
    expect($signed['sig'])->toBe(md5('name=Jane&note=&uniqueid=7secret'));
});

test('http_build_query encodes spaces as plus, as the legacy library did', function () {
    $signed = app(JsConnect::class)->sign(['name' => 'Jane D'], JSC_CLIENT, JSC_SECRET);

    expect($signed['sig'])->toBe(md5('name=Jane+D'.JSC_SECRET));
});
