<?php

use App\Services\Auth\TurnstileVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const TURNSTILE_SECRET = 'ts-secret-value-123';
const TURNSTILE_TOKEN = 'ts-token-value-456';

beforeEach(function () {
    config(['sesf.turnstile.secret' => TURNSTILE_SECRET]);
});

/**
 * Collects every message written to the log while $callback runs.
 *
 * @param  callable(): mixed  $callback
 * @return list<string>
 */
function collectLogMessages(callable $callback): array
{
    $messages = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$messages): void {
        $messages[] = $event->message.' '.json_encode($event->context);
    });

    $callback();

    return $messages;
}

test('returns true when Cloudflare reports success', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, '203.0.113.5'))->toBeTrue();
});

test('returns false when Cloudflare rejects the token', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response([
        'success' => false,
        'error-codes' => ['timeout-or-duplicate'],
    ])]);

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, '203.0.113.5'))->toBeFalse();
});

test('returns false when success is a truthy string rather than true', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => 'true'])]);

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null))->toBeFalse();
});

test('returns false on a connection exception', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null))->toBeFalse();
});

test('returns false when the request times out after connecting', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, '203.0.113.5'))->toBeFalse();
});

test('returns false for a malformed JSON body', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response('<html>oops</html>', 200)]);

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null))->toBeFalse();
});

test('returns false for a server error even when the body claims success', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true], 500)]);

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null))->toBeFalse();
});

test('returns false for an empty or blank token without calling Cloudflare', function () {
    Http::fake();

    expect(app(TurnstileVerifier::class)->verify('', '203.0.113.5'))->toBeFalse();
    expect(app(TurnstileVerifier::class)->verify("  \t ", '203.0.113.5'))->toBeFalse();

    Http::assertNothingSent();
});

test('returns false when the secret is not configured, without calling Cloudflare', function () {
    config(['sesf.turnstile.secret' => '']);
    Http::fake();

    expect(app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, '203.0.113.5'))->toBeFalse();

    Http::assertNothingSent();
});

test('posts the secret and token as a form to the siteverify endpoint', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, '203.0.113.5');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request->method() === 'POST'
        && $request['secret'] === TURNSTILE_SECRET
        && $request['response'] === TURNSTILE_TOKEN);
});

test('forwards the client ip as remoteip', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, '203.0.113.5');

    Http::assertSent(fn (Request $request) => $request['remoteip'] === '203.0.113.5');
});

test('omits remoteip when no client ip is known', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null);

    Http::assertSent(fn (Request $request) => ! array_key_exists('remoteip', $request->data()));
});

test('never logs the secret or the token, even when the request fails', function () {
    Http::fake(fn () => throw new ConnectionException('failed with '.TURNSTILE_SECRET.' and '.TURNSTILE_TOKEN));

    $messages = collectLogMessages(fn () => app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null));

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        expect($message)->not->toContain(TURNSTILE_SECRET);
        expect($message)->not->toContain(TURNSTILE_TOKEN);
    }
});

test('never logs the secret or the token when Cloudflare rejects the token', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    $messages = collectLogMessages(fn () => app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null));

    foreach ($messages as $message) {
        expect($message)->not->toContain(TURNSTILE_SECRET);
        expect($message)->not->toContain(TURNSTILE_TOKEN);
    }
});

test('never logs the secret when the secret is missing', function () {
    config(['sesf.turnstile.secret' => '']);

    $messages = collectLogMessages(fn () => app(TurnstileVerifier::class)->verify(TURNSTILE_TOKEN, null));

    expect($messages)->toHaveCount(1);
    expect($messages[0])->not->toContain(TURNSTILE_TOKEN);
});
