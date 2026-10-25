<?php

use App\Enums\UserType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

const SSO_NOW = 1800000000;
const SSO_SECRET = 'sso-route-secret';
const SSO_CLIENT = 'sso-route-client';

beforeEach(function () {
    config([
        'app.url' => 'https://sesf.test',
        'sesf.vanilla.client_id' => SSO_CLIENT,
        'sesf.vanilla.secret' => SSO_SECRET,
    ]);

    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(SSO_NOW));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function ssoQuery(array $overrides = []): array
{
    $query = [
        'v' => '2',
        'client_id' => SSO_CLIENT,
        'timestamp' => (string) SSO_NOW,
        'nonce' => 'nonce-1',
        'ip' => '203.0.113.9',
    ];
    $query['sig'] = md5($query['ip'].$query['nonce'].$query['timestamp'].SSO_SECRET);

    return array_merge($query, $overrides);
}

/**
 * GET /login/vanilla through the HTTP kernel, as $as (null for a guest).
 *
 * @param  array<string, string>  $query
 */
function ssoGet(array $query, ?User $as = null): Response
{
    // The app resolves $request->user() through the default guard (the request is rebound by the
    // auth provider, which replaces any resolver set on it). Fresh guards per call keep users apart.
    Auth::forgetGuards();

    if ($as !== null) {
        Auth::guard()->setUser($as);
    }

    $request = Request::create('/login/vanilla?'.http_build_query($query), 'GET');

    return app(Kernel::class)->handle($request);
}

/**
 * The decoded jsConnect reply, with any JSONP wrapper removed.
 *
 * @return array<string, mixed>
 */
function ssoBody(Response $response): array
{
    $body = (string) $response->getContent();

    if (str_starts_with($body, 'cb(')) {
        $body = substr($body, 3, -1);
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

test('an anonymous visitor gets the empty-user reply, not an empty body', function () {
    $response = ssoGet(ssoQuery());

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('{"name":"","photourl":""}');
    expect($response->headers->get('Content-Type'))->toBe('application/json');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('a signed-in member gets the legacy identity, signed', function () {
    $user = User::factory()->create([
        'first' => 'Jane',
        'last' => 'Doe',
        'email' => 'jane@example.com',
        'type_id' => UserType::Regular->value,
    ]);

    $response = ssoGet(ssoQuery(), $user);
    $body = ssoBody($response);

    expect($response->getStatusCode())->toBe(200);
    expect($body['name'])->toBe('Jane_D_'.$user->id);
    expect($body['uniqueid'])->toBe($user->id);
    expect($body['email'])->toBe('jane@example.com');
    expect($body['roles'])->toBe('member');
    expect($body['photourl'])->toBe('https://sesf.test/'.$user->imageURL);
    expect($body['client_id'])->toBe(SSO_CLIENT);
    expect($body['v'])->toBe('2');
});

test('the signed-in reply signature verifies', function () {
    $user = User::factory()->create();

    $body = ssoBody(ssoGet(ssoQuery(), $user));

    $signed = $body;
    unset($signed['client_id'], $signed['sig'], $signed['v']);
    ksort($signed);

    expect($body['sig'])->toBe(md5(http_build_query($signed, '', '&').SSO_SECRET));
});

test('an administrator gets the administrator role', function () {
    $admin = User::factory()->admin()->create();

    expect(ssoBody(ssoGet(ssoQuery(), $admin))['roles'])->toBe('member,administrator');
});

test('a bad signature is refused with access_denied', function () {
    $user = User::factory()->create();

    expect(ssoBody(ssoGet(ssoQuery(['sig' => 'deadbeef']), $user)))
        ->toBe(['error' => 'access_denied', 'message' => 'Signature invalid.']);
});

test('an expired timestamp is refused', function () {
    $response = ssoGet(ssoQuery(['timestamp' => (string) (SSO_NOW - 1441)]));

    expect($response->getContent())->toContain('The timestamp is invalid.');
});

test('a wrong client id is refused', function () {
    expect(ssoBody(ssoGet(ssoQuery(['client_id' => 'other']))))
        ->toBe(['error' => 'invalid_client', 'message' => 'Unknown client other.']);
});

test('a callback that is not an identifier is refused as JSON and not echoed', function () {
    $user = User::factory()->create();

    $response = ssoGet(ssoQuery(['callback' => 'alert(document.cookie)']), $user);

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('application/json');
    expect($response->getContent())->not->toContain('alert');
    expect(ssoBody($response))->toBe(['error' => 'invalid_request', 'message' => 'The callback parameter is invalid.']);
});

test('a valid callback returns JSONP with a javascript content type', function () {
    $user = User::factory()->create();

    $response = ssoGet(ssoQuery(['callback' => 'jQuery_1']), $user);

    expect($response->headers->get('Content-Type'))->toBe('application/javascript');
    expect($response->getContent())->toStartWith('jQuery_1({"email":');
});

test('with no client secret configured, the endpoint refuses and never signs', function () {
    config(['sesf.vanilla.secret' => '']);
    $user = User::factory()->create();

    expect(ssoBody(ssoGet(ssoQuery(), $user)))
        ->toBe(['error' => 'invalid_client', 'message' => 'SSO is not configured.']);
});

test('the response never contains the secret', function () {
    $user = User::factory()->create();

    expect(ssoGet(ssoQuery(), $user)->getContent())->not->toContain(SSO_SECRET);
});

test('every jsConnect reply, including errors and JSONP, says nosniff', function () {
    $user = User::factory()->create();

    $replies = [
        ssoGet(ssoQuery()),
        ssoGet(ssoQuery(), $user),
        ssoGet(ssoQuery(['sig' => 'deadbeef']), $user),
        ssoGet(ssoQuery(['client_id' => 'other'])),
        ssoGet(ssoQuery(['callback' => 'cb']), $user),
    ];

    foreach ($replies as $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
});

test('a client id that is not a plain identifier is not echoed into the error reply', function () {
    $response = ssoGet(ssoQuery(['client_id' => '</script><script>alert(1)</script>']));

    expect($response->getContent())
        ->toBe('{"error":"invalid_client","message":"Unknown client."}')
        ->and($response->headers->get('Content-Type'))->toBe('application/json');
});

test('a plain client id is still echoed in the error reply', function () {
    expect(ssoBody(ssoGet(ssoQuery(['client_id' => 'other-client']))))
        ->toBe(['error' => 'invalid_client', 'message' => 'Unknown client other-client.']);
});
