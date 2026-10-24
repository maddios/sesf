<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

it('fails any request that was not faked', function () {
    Http::get('https://example.com/not-faked');
})->throws(StrayRequestException::class);

it('runs on the in-memory sqlite default connection', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

it('runs with the forced phpunit.xml values', function () {
    expect(config('mail.default'))->toBe('array')
        ->and(config('sesf.email_debug'))->toBeTrue()
        ->and(config('sesf.dev_address'))->toBe('dev@example.test')
        ->and(config('sesf.google.geocode_key'))->toBe('')
        ->and(config('sesf.turnstile.secret'))->toBe('')
        ->and(config('sesf.datadog.rum.enabled'))->toBeFalse()
        ->and(config('sesf.datadog.logs.enabled'))->toBeFalse();
});
