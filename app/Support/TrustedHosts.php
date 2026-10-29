<?php

namespace App\Support;

/**
 * Host patterns the app answers to: the host of APP_URL only, plus "localhost" in local and testing.
 * Subdomains are not trusted. Laravel's TrustHosts middleware uses these patterns outside local and
 * testing, so a forged Host header is rejected before any URL is built from it.
 */
final class TrustedHosts
{
    /**
     * @return array<int, string>
     */
    public static function patterns(): array
    {
        $hosts = [];

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }

        if (app()->environment(['local', 'testing'])) {
            $hosts[] = 'localhost';
        }

        return array_values(array_unique(array_map(
            static fn (string $host): string => '^'.preg_quote($host).'$',
            $hosts,
        )));
    }
}
