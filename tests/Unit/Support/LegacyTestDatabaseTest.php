<?php

use Illuminate\Database\ConnectionInterface;
use Mockery\MockInterface;
use Tests\Support\LegacyTestDatabase;

/**
 * A fake of the connection surface that LegacyTestDatabase uses. Row counts live in $state.
 * INSERTs add the rows they contain (parsed by the code under test); TRUNCATE empties a table.
 * $dropInsertsFor silently discards the inserts for one table, to simulate a short load.
 * Every unprepared() statement is appended to $calls.
 *
 * @param  array<string, int>  $state
 * @param  ArrayObject<int, string>  $calls
 */
function fakeLookupConnection(array &$state, ArrayObject $calls, ?string $dropInsertsFor = null, string $database = 'sesf_test'): ConnectionInterface
{
    /** @var ConnectionInterface&MockInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);

    $connection->allows('selectOne')->andReturnUsing(function (string $sql) use (&$state, $database) {
        if (str_contains($sql, 'database()')) {
            return (object) ['db' => $database];
        }

        preg_match('/from `([^`]+)`/', $sql, $matches);

        return (object) ['n' => $state[$matches[1]] ?? 0];
    });

    $connection->allows('unprepared')->andReturnUsing(function (string $sql) use (&$state, $calls, $dropInsertsFor) {
        $calls->append($sql);

        if (preg_match('/^TRUNCATE TABLE `([^`]+)`/', $sql, $matches)) {
            $state[$matches[1]] = 0;
        } elseif (preg_match('/^INSERT/i', $sql)) {
            foreach (LegacyTestDatabase::fixtureRowCounts($sql)['rows'] as $table => $rows) {
                if ($table !== $dropInsertsFor) {
                    $state[$table] = ($state[$table] ?? 0) + $rows;
                }
            }
        }

        return true;
    });

    return $connection;
}

/** @return array{order: list<string>, rows: array<string, int>} */
function realLookupFixture(): array
{
    return LegacyTestDatabase::fixtureRowCounts(file_get_contents(base_path(LegacyTestDatabase::LOOKUP_FIXTURE)));
}

/** A fixture in a temp file, so loadLookupFixture reads a path the test controls. */
function tempFixture(string $sql): string
{
    $path = tempnam(sys_get_temp_dir(), 'lookup-fixture-');
    file_put_contents($path, $sql);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

function lastTable(): string
{
    return realLookupFixture()['order'][count(realLookupFixture()['order']) - 1];
}

// --- Host and session guard -------------------------------------------------------------

it('allows the testdb host', function () {
    expect(fn () => LegacyTestDatabase::assertSafe(['database' => 'sesf_test', 'host' => 'testdb'], false))
        ->not->toThrow(RuntimeException::class);
});

it('refuses any other host outside CI', function (string $host) {
    LegacyTestDatabase::assertSafe(['database' => 'sesf_test', 'host' => $host], false);
})->with(['mysql', 'db', '10.0.0.5', 'host.docker.internal', '127.0.0.1', 'localhost', ''])
    ->throws(RuntimeException::class, 'Allowed hosts');

it('allows 127.0.0.1 and localhost only when CI=true', function (string $host) {
    expect(fn () => LegacyTestDatabase::assertSafe(['database' => 'sesf_test', 'host' => $host], true))
        ->not->toThrow(RuntimeException::class);
})->with(['127.0.0.1', 'localhost']);

it('still refuses a non-allowed host when CI=true', function () {
    LegacyTestDatabase::assertSafe(['database' => 'sesf_test', 'host' => 'prod-db.internal'], true);
})->throws(RuntimeException::class, 'prod-db.internal');

it('refuses any database other than sesf_test, whatever the host', function () {
    LegacyTestDatabase::assertSafe(['database' => 'sesf_dev', 'host' => 'testdb'], false);
})->throws(RuntimeException::class, 'sesf_dev');

it('re-checks the live session database on every call', function () {
    $state = [];
    $calls = new ArrayObject;

    $connection = fakeLookupConnection($state, $calls, database: 'sesf_dev');

    LegacyTestDatabase::assertConnected($connection, ['database' => 'sesf_test', 'host' => 'testdb'], false);
})->throws(RuntimeException::class, 'session is connected to database [sesf_dev]');

it('passes the per-test check when config and session both point at sesf_test', function () {
    $state = [];
    $connection = fakeLookupConnection($state, new ArrayObject);

    expect(fn () => LegacyTestDatabase::assertConnected($connection, ['database' => 'sesf_test', 'host' => 'testdb'], false))
        ->not->toThrow(RuntimeException::class);
});

// --- Fixture row counting ---------------------------------------------------------------

it('counts VALUES tuples, ignoring parentheses and escaped quotes inside strings', function () {
    $sql = <<<'SQL'
INSERT INTO `t` (`valueStep`,`b`) VALUES
('x),(y','it''s'),
('a\'),(b','c')
SQL;

    expect(LegacyTestDatabase::fixtureRowCounts($sql))->toBe(['order' => ['t'], 'rows' => ['t' => 2]]);
});

it('counts the real lookup fixture: every tuple line starts with a quoted id', function () {
    $sql = file_get_contents(base_path(LegacyTestDatabase::LOOKUP_FIXTURE));
    $fixture = LegacyTestDatabase::fixtureRowCounts($sql);

    expect($fixture['order'])->toHaveCount(14)
        ->and($fixture['rows']['user_type'])->toBe(3)
        ->and(array_sum($fixture['rows']))->toBe(preg_match_all("/^\\('/m", $sql));
});

// --- Fixture loading --------------------------------------------------------------------

it('loads the whole fixture into an empty database and verifies the last table', function () {
    $expected = realLookupFixture();
    $state = [];
    $calls = new ArrayObject;

    LegacyTestDatabase::loadLookupFixture(fakeLookupConnection($state, $calls), base_path(LegacyTestDatabase::LOOKUP_FIXTURE));

    foreach ($expected['rows'] as $table => $rows) {
        expect($state[$table])->toBe($rows);
    }
    expect($calls->getArrayCopy())->toContain('SET FOREIGN_KEY_CHECKS=1');
});

it('skips a complete fixture without writing anything', function () {
    $expected = realLookupFixture();
    $state = $expected['rows'];
    $calls = new ArrayObject;

    LegacyTestDatabase::loadLookupFixture(fakeLookupConnection($state, $calls), base_path(LegacyTestDatabase::LOOKUP_FIXTURE));

    expect($calls->getArrayCopy())->toBe([]);
});

it('truncates and reloads when user_type has rows but the last table is empty', function () {
    $expected = realLookupFixture();
    $state = ['user_type' => 3];
    $calls = new ArrayObject;

    LegacyTestDatabase::loadLookupFixture(fakeLookupConnection($state, $calls), base_path(LegacyTestDatabase::LOOKUP_FIXTURE));

    /** @var list<string> $all */
    $all = $calls->getArrayCopy();
    $truncates = array_values(array_filter($all, fn (string $sql) => str_starts_with($sql, 'TRUNCATE')));
    expect($truncates)->toBe(array_map(fn (string $table) => "TRUNCATE TABLE `{$table}`", $expected['order']))
        ->and($state[lastTable()])->toBe($expected['rows'][lastTable()])
        ->and($state['user_type'])->toBe(3);
});

it('reloads when the last table is short even if the probe table is full', function () {
    $expected = realLookupFixture();
    $last = lastTable();
    $state = $expected['rows'];
    $state[$last] = $expected['rows'][$last] - 1;
    $calls = new ArrayObject;

    LegacyTestDatabase::loadLookupFixture(fakeLookupConnection($state, $calls), base_path(LegacyTestDatabase::LOOKUP_FIXTURE));

    expect($state[$last])->toBe($expected['rows'][$last])
        ->and($calls->count())->toBeGreaterThan(0);
});

it('throws when the last table is still short after the load', function () {
    $state = [];
    $calls = new ArrayObject;
    $connection = fakeLookupConnection($state, $calls, dropInsertsFor: lastTable());

    LegacyTestDatabase::loadLookupFixture($connection, base_path(LegacyTestDatabase::LOOKUP_FIXTURE));
})->throws(RuntimeException::class, 'is incomplete');

it('names the fixture file when a statement fails', function () {
    /** @var ConnectionInterface&MockInterface $connection */
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->allows('selectOne')->andReturn((object) ['n' => 0]);
    $connection->allows('unprepared')->andReturnUsing(function (string $sql) {
        if (str_starts_with($sql, 'INSERT')) {
            throw new RuntimeException('boom');
        }

        return true;
    });

    LegacyTestDatabase::loadLookupFixture($connection, tempFixture("INSERT INTO `user_type` (`id`) VALUES\n('1');"));
})->throws(RuntimeException::class, 'Lookup fixture [lookup-fixture-');

it('loads a temp fixture through the configurable path', function () {
    $path = tempFixture("INSERT INTO `user_type` (`id`) VALUES\n('1'),\n('2');");
    $state = [];

    LegacyTestDatabase::loadLookupFixture(fakeLookupConnection($state, new ArrayObject), $path);

    expect($state['user_type'])->toBe(2);
});
