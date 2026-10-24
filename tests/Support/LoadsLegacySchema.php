<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * For database tests (tests/Database/**). Runs against the ephemeral testing_mariadb
 * connection only, after the safety guard in LegacyTestDatabase has passed:
 *
 *  - once per process, loads docs/legacy/schema-sesf_dev.sql (structure only) into
 *    sesf_test if the tables are not there yet, then applies database/sql/*.sql(.done);
 *  - wraps each test in a transaction that is rolled back at teardown.
 *
 * A test that needs real autocommit or DDL behaviour overrides legacyTransactional()
 * and returns false.
 */
// The trait is applied by Pest's ->use() in tests/Pest.php, which PHPStan cannot see.
// @phpstan-ignore-next-line trait.unused
trait LoadsLegacySchema
{
    protected function setUpLoadsLegacySchema(): void
    {
        $config = config('database.connections.'.LegacyTestDatabase::CONNECTION);
        LegacyTestDatabase::assertSafe(is_array($config) ? $config : []);

        config(['database.default' => LegacyTestDatabase::CONNECTION]);
        $connection = DB::connection(LegacyTestDatabase::CONNECTION);

        // Defence in depth: the server must agree with the config about which database it is.
        $actual = (string) $connection->selectOne('select database() as db')->db;
        if ($actual !== LegacyTestDatabase::REQUIRED_DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to run database tests: session is connected to database [%s], expected [%s].',
                $actual,
                LegacyTestDatabase::REQUIRED_DATABASE,
            ));
        }

        LegacyTestDatabase::ensureSchema($connection, base_path(LegacyTestDatabase::SCHEMA_FILE));

        if ($this->legacyTransactional()) {
            $connection->beginTransaction();

            $this->beforeApplicationDestroyed(function () use ($connection): void {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            });
        }
    }

    /**
     * Whether each test runs inside a rolled-back transaction. Override to return false
     * for tests that need autocommit or DDL behaviour.
     */
    protected function legacyTransactional(): bool
    {
        return true;
    }
}
