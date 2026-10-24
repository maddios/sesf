<?php

use App\Services\Geocoder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['sesf.google.geocode_key' => 'test-key']);
});

test('returns lattitude and longitude as floats for an OK result', function () {
    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => 29.1234, 'lng' => -81.0456]]]],
        ]),
    ]);

    expect(app(Geocoder::class)->geocode('1002 S Orange Ave, Orlando, FL 32803'))
        ->toBe(['lattitude' => 29.1234, 'longitude' => -81.0456]);
});

test('sends the address and key and asks for the US region', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    app(Geocoder::class)->geocode('1 Main St, Orlando, FL 32803');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')
        && $request->data()['address'] === '1 Main St, Orlando, FL 32803'
        && $request->data()['key'] === 'test-key'
        && $request->data()['region'] === 'us');
});

test('returns null when Google finds no result', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    expect(app(Geocoder::class)->geocode('nowhere'))->toBeNull();
});

test('returns null for an HTTP error', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response('server error', 500)]);

    expect(app(Geocoder::class)->geocode('1 Main St'))->toBeNull();
});

test('returns null for a malformed body', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [['geometry' => []]]])]);

    expect(app(Geocoder::class)->geocode('1 Main St'))->toBeNull();
});

test('returns null instead of throwing when the request times out', function () {
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect(app(Geocoder::class)->geocode('1 Main St'))->toBeNull();
});

test('makes no request for a blank address or a missing key', function () {
    Http::fake();

    expect(app(Geocoder::class)->geocode('   '))->toBeNull();

    config(['sesf.google.geocode_key' => '']);
    expect(app(Geocoder::class)->geocode('1 Main St'))->toBeNull();

    Http::assertNothingSent();
});
