<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;

/**
 * Expected table names, read from the committed structure dump so the test tracks the
 * schema file rather than a hand-maintained list.
 *
 * @return list<string>
 */
function expectedLegacyTables(): array
{
    $sql = file_get_contents(base_path(LegacyTestDatabase::SCHEMA_FILE));
    preg_match_all('/^CREATE TABLE `([^`]+)`/m', (string) $sql, $matches);

    return $matches[1];
}

function legacyConnection(): Connection
{
    return DB::connection(LegacyTestDatabase::CONNECTION);
}

test('the test database is sesf_test and the legacy schema has 65 tables', function () {
    $database = legacyConnection()->selectOne('select database() as db')->db;

    expect($database)->toBe('sesf_test');
    expect(expectedLegacyTables())->toHaveCount(65);
});

test('every table from the legacy schema dump exists in the test database', function () {
    $existing = array_map(
        fn ($row) => $row->table_name,
        legacyConnection()->select('select table_name from information_schema.tables where table_schema = database()'),
    );

    foreach (expectedLegacyTables() as $table) {
        expect($existing)->toContain($table);
    }

    expect($existing)->toHaveCount(65);
});

test('user has an email column', function () {
    $columns = legacyConnection()->select(
        'select column_name from information_schema.columns where table_schema = database() and table_name = ?',
        ['user'],
    );

    expect(array_map(fn ($c) => $c->column_name, $columns))->toContain('email');
});

test('car_show has a shirt_stock_json column', function () {
    $columns = legacyConnection()->select(
        'select column_name from information_schema.columns where table_schema = database() and table_name = ?',
        ['car_show'],
    );

    expect(array_map(fn ($c) => $c->column_name, $columns))->toContain('shirt_stock_json');
});

test('the safety guard refuses any database other than sesf_test', function () {
    expect(fn () => LegacyTestDatabase::assertSafe(['host' => 'testdb', 'database' => 'sesf_dev']))
        ->toThrow(RuntimeException::class, 'must be [sesf_test]');

    expect(fn () => LegacyTestDatabase::assertSafe(['host' => 'testdb', 'database' => 'sesf']))
        ->toThrow(RuntimeException::class);
});

test('the safety guard refuses a missing host', function () {
    expect(fn () => LegacyTestDatabase::assertSafe(['host' => '', 'database' => 'sesf_test']))
        ->toThrow(RuntimeException::class, 'no host configured');
});

test('each test runs inside a transaction on the test connection', function () {
    expect(legacyConnection()->transactionLevel())->toBe(1);
});

test('isolation: a row inserted by one test is visible inside that test', function () {
    legacyConnection()->table('announcements')->insert([
        'show_id' => 1,
        'text' => 'isolation-marker',
    ]);

    expect(legacyConnection()->table('announcements')->where('text', 'isolation-marker')->count())->toBe(1);
});

test('isolation: a row inserted by the previous test is not visible here', function () {
    expect(legacyConnection()->table('announcements')->where('text', 'isolation-marker')->count())->toBe(0);
});
