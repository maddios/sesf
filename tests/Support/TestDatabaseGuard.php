<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Last line of defence before any test runs: the default connection must be the in-memory
 * SQLite database or the ephemeral testing_mariadb connection, and config must not be cached
 * (a cached config would skip phpunit.xml overrides and .env test values).
 */
final class TestDatabaseGuard
{
    public const MARIADB_CONNECTION = 'testing_mariadb';

    /**
     * @param  array<string, mixed>|null  $connection  Config of the default connection, if any.
     *
     * @throws RuntimeException when the configuration is cached or the default connection is not allowed.
     */
    public static function assertSafe(bool $configurationCached, string $defaultConnection, ?array $connection): void
    {
        if ($configurationCached) {
            throw new RuntimeException(
                'Refusing to run tests: configuration is cached (bootstrap/cache/config.php). '
                .'Run "php artisan config:clear" first.'
            );
        }

        if ($defaultConnection === self::MARIADB_CONNECTION) {
            return;
        }

        if (($connection['driver'] ?? null) === 'sqlite' && ($connection['database'] ?? null) === ':memory:') {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests: default connection [%s] (driver [%s], database [%s]) is not allowed. '
            .'Tests use in-memory sqlite or [%s] only.',
            $defaultConnection,
            is_scalar($connection['driver'] ?? null) ? (string) $connection['driver'] : 'unknown',
            is_scalar($connection['database'] ?? null) ? (string) $connection['database'] : 'unknown',
            self::MARIADB_CONNECTION,
        ));
    }

    /**
     * @throws RuntimeException see assertSafe().
     */
    public static function assertApplication(Application $app): void
    {
        $config = $app->make('config');
        $default = (string) $config->get('database.default');
        $connection = $config->get('database.connections.'.$default);

        self::assertSafe(
            $app->configurationIsCached(),
            $default,
            is_array($connection) ? $connection : null,
        );
    }
}
