<?php

namespace App\Support;

/**
 * Decides whether a database connection may be the target of destructive commands
 * (migrate:fresh, db:wipe, ...).
 *
 * Only two connections qualify: in-memory SQLite, and the testing_mariadb connection when it
 * resolves to the sesf_test database. Everything else, including the shared mysql (sesf_dev)
 * connection, is refused whatever APP_ENV or DB_* variables say.
 */
final class DestructiveCommandPolicy
{
    /** Connection name of the ephemeral MariaDB used by tests (see config/database.php). */
    public const TEST_CONNECTION = 'testing_mariadb';

    /** The only database name the testing_mariadb connection may resolve to. */
    public const TEST_DATABASE = 'sesf_test';

    /**
     * @param  array<string, mixed>  $connectionConfig  config("database.connections.{$name}")
     */
    public static function allowsTarget(array $connectionConfig, string $name): bool
    {
        if ($name === self::TEST_CONNECTION) {
            return ($connectionConfig['database'] ?? null) === self::TEST_DATABASE;
        }

        return ($connectionConfig['driver'] ?? null) === 'sqlite'
            && ($connectionConfig['database'] ?? null) === ':memory:';
    }

    /**
     * Used for the global DB::prohibitDestructiveCommands() flag, which is decided from the default
     * connection only. The per-command guard (DestructiveCommandGuard) covers other targets.
     *
     * @param  array<string, mixed>  $connectionConfig  config("database.connections.{$defaultName}")
     * @param  string  $defaultName  config('database.default')
     */
    public static function shouldProhibit(array $connectionConfig, string $defaultName): bool
    {
        return ! self::allowsTarget($connectionConfig, $defaultName);
    }
}
