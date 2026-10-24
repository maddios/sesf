<?php

namespace App\Support;

/**
 * Decides whether destructive database commands (migrate:fresh, db:wipe, ...) are refused.
 *
 * They are allowed only on the ephemeral test databases: in-memory SQLite and the
 * testing_mariadb connection. Everything else, including the shared mysql (sesf_dev) connection,
 * is refused, whatever APP_ENV says.
 */
final class DestructiveCommandPolicy
{
    /** Connection name of the ephemeral MariaDB used by tests (see config/database.php). */
    public const TEST_CONNECTION = 'testing_mariadb';

    /**
     * @param  array<string, mixed>  $connectionConfig  config("database.connections.{$defaultName}")
     * @param  string  $defaultName  config('database.default')
     */
    public static function shouldProhibit(array $connectionConfig, string $defaultName): bool
    {
        if ($defaultName === self::TEST_CONNECTION) {
            return false;
        }

        $isMemorySqlite = ($connectionConfig['driver'] ?? null) === 'sqlite'
            && ($connectionConfig['database'] ?? null) === ':memory:';

        return ! $isMemorySqlite;
    }
}
