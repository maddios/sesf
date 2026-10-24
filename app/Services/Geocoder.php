<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Looks up latitude and longitude for a postal address with the Google Geocoding API.
 *
 * Never throws for lookup problems: timeouts, HTTP errors, and "no result" all return null,
 * and the failure is logged without the address or the key.
 */
class Geocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const TIMEOUT_SECONDS = 5;

    /**
     * @return array{lattitude: float, longitude: float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        $key = (string) config('sesf.google.geocode_key');

        if ($address === '' || $key === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::ENDPOINT, [
                    'address' => $address,
                    'key' => $key,
                    'region' => 'us',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Geocoding request timed out or could not connect.');

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Geocoding request failed.', ['status' => $response->status()]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'OK') {
            return null;
        }

        $location = $body['results'][0]['geometry']['location'] ?? null;

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        return [
            'lattitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
        ];
    }
}
