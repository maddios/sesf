<?php

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Safety guard and schema loader for the ephemeral test database. Tests must never
 * touch the shared sesf_dev database, so every connection used by database tests is
 * checked here before any schema load or transaction.
 */
final class LegacyTestDatabase
{
    public const REQUIRED_DATABASE = 'sesf_test';

    public const CONNECTION = 'testing_mariadb';

    /** Structure-only dump of the live schema, relative to the project root. */
    public const SCHEMA_FILE = 'docs/legacy/schema-sesf_dev.sql';

    /** Schema change files applied after the dump, relative to the project root. */
    public const SQL_DIRECTORY = 'database/sql';

    /** Statement separator used by the dump and by database/sql files. */
    private const STATEMENT_SEPARATOR = ";\n\n";

    private static bool $ready = false;

    /**
     * Makes the test database match the legacy schema, once per process:
     *
     *  1. loads the dump if none of its tables exist yet (fails on a partial schema);
     *  2. applies every database/sql file (see applySqlDirectory).
     *
     * The ready flag is set only after both steps succeed, so a failing statement fails
     * every test that needs the database, not just the first one.
     *
     * @throws RuntimeException on a partial schema, an unreadable file, or a failed statement.
     */
    public static function ensureSchema(
        ConnectionInterface $connection,
        string $schemaPath,
        ?string $sqlDirectory = null,
    ): void {
        if (self::$ready) {
            return;
        }

        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            throw new RuntimeException("Cannot read legacy schema dump at [{$schemaPath}].");
        }

        preg_match_all('/^CREATE TABLE `([^`]+)`/m', $sql, $matches);
        $expected = $matches[1];

        $existing = array_map(
            static fn (object $row): string => (string) $row->table_name,
            $connection->select(
                'select table_name from information_schema.tables where table_schema = database()'
            ),
        );

        $present = array_values(array_intersect($expected, $existing));

        if (count($present) !== count($expected)) {
            if ($present !== []) {
                throw new RuntimeException(sprintf(
                    'Test database [%s] has a partial legacy schema (%d of %d tables present). '
                    .'Run "docker compose --profile test rm -sf testdb" and retry to get a fresh database.',
                    self::REQUIRED_DATABASE,
                    count($present),
                    count($expected),
                ));
            }

            $connection->unprepared('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach (self::statements($sql) as $statement) {
                    $connection->unprepared($statement);
                }
            } finally {
                $connection->unprepared('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        self::applySqlDirectory($connection, $sqlDirectory ?? base_path(self::SQL_DIRECTORY));

        self::$ready = true;
    }

    /**
     * Applies every file in $directory named *.sql or *.sql.done, ordered by filename with
     * the .done suffix ignored. A missing or empty directory is a no-op. Statements are
     * split like the schema dump. Any failing statement throws and names the file.
     *
     * @return list<string> Applied file names, in the order they ran.
     *
     * @throws RuntimeException when a statement fails.
     */
    public static function applySqlDirectory(ConnectionInterface $connection, string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $names = scandir($directory);
        if ($names === false) {
            throw new RuntimeException("Cannot list SQL directory [{$directory}].");
        }

        $files = array_values(array_filter(
            $names,
            static fn (string $name): bool => (bool) preg_match('/\.sql(\.done)?$/', $name)
                && is_file($directory.DIRECTORY_SEPARATOR.$name),
        ));

        usort($files, static function (string $a, string $b): int {
            $byStem = strcmp(preg_replace('/\.done$/', '', $a), preg_replace('/\.done$/', '', $b));

            return $byStem !== 0 ? $byStem : strcmp($a, $b);
        });

        foreach ($files as $name) {
            $sql = file_get_contents($directory.DIRECTORY_SEPARATOR.$name);
            if ($sql === false) {
                throw new RuntimeException("Cannot read SQL file [{$name}].");
            }

            foreach (self::statements($sql) as $statement) {
                try {
                    $connection->unprepared($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        "SQL file [{$name}] failed: {$e->getMessage()}",
                        0,
                        $e,
                    );
                }
            }
        }

        return $files;
    }

    /**
     * @return list<string> Non-empty statements, in file order, with nothing removed from them.
     */
    private static function statements(string $sql): array
    {
        return array_values(array_filter(
            array_map('trim', explode(self::STATEMENT_SEPARATOR, $sql)),
            static fn (string $chunk): bool => $chunk !== '',
        ));
    }

    /**
     * @param  array{host?: mixed, database?: mixed}  $connection  Laravel connection config (or a subset).
     *
     * @throws RuntimeException when the database name is not sesf_test or the host is empty.
     */
    public static function assertSafe(array $connection): void
    {
        $database = $connection['database'] ?? null;
        $host = $connection['host'] ?? null;

        if ($database !== self::REQUIRED_DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to run database tests: connection "%s" resolves to database [%s] on host [%s]; '
                .'it must be [%s]. Tests never run against sesf_dev or any other database.',
                self::CONNECTION,
                is_scalar($database) ? (string) $database : gettype($database),
                is_scalar($host) ? (string) $host : gettype($host),
                self::REQUIRED_DATABASE,
            ));
        }

        if (! is_string($host) || $host === '') {
            throw new RuntimeException(sprintf(
                'Refusing to run database tests: connection "%s" has no host configured (DB_TEST_HOST).',
                self::CONNECTION,
            ));
        }
    }
}
