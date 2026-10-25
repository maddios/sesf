<?php

use App\Rules\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    config(['sesf.turnstile.secret' => 'rule-secret']);
});

test('the rule passes when Cloudflare accepts the token', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $validator = Validator::make(['cf' => 'token'], ['cf' => [new Turnstile('203.0.113.5')]]);

    expect($validator->passes())->toBeTrue();
});

test('the rule fails with the legacy message when Cloudflare rejects the token', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $validator = Validator::make(['cf' => 'token'], ['cf' => [new Turnstile('203.0.113.5')]]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('cf'))->toBe('Please complete the security check before submitting.');
});

test('the rule fails when the request times out', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $validator = Validator::make(['cf' => 'token'], ['cf' => [new Turnstile('203.0.113.5')]]);

    expect($validator->fails())->toBeTrue();
});

test('a missing token fails the required rule without calling Cloudflare', function () {
    Http::fake();

    // Laravel skips non-implicit rules for an absent field, so callers pair the rule with 'required'
    // (legacy: 'required|turnstile').
    $validator = Validator::make([], ['cf' => ['required', new Turnstile('203.0.113.5')]]);

    expect($validator->fails())->toBeTrue();
    Http::assertNothingSent();
});

test('a blank token fails without calling Cloudflare', function () {
    Http::fake();

    $validator = Validator::make(['cf' => '   '], ['cf' => ['required', new Turnstile('203.0.113.5')]]);

    expect($validator->fails())->toBeTrue();
    Http::assertNothingSent();
});
