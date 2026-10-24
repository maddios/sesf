<?php

use Tests\Support\TestDatabaseGuard;

$memorySqlite = ['driver' => 'sqlite', 'database' => ':memory:'];

it('allows the in-memory sqlite default connection', function () use ($memorySqlite) {
    expect(fn () => TestDatabaseGuard::assertSafe(false, 'sqlite', $memorySqlite))
        ->not->toThrow(RuntimeException::class);
});

it('allows the ephemeral testing_mariadb default connection', function () {
    expect(fn () => TestDatabaseGuard::assertSafe(false, 'testing_mariadb', ['driver' => 'mysql', 'database' => 'sesf_test']))
        ->not->toThrow(RuntimeException::class);
});

it('refuses a mysql default connection, such as one pointing at sesf_dev', function () {
    TestDatabaseGuard::assertSafe(false, 'mysql', ['driver' => 'mysql', 'database' => 'sesf_dev']);
})->throws(RuntimeException::class, 'default connection [mysql]');

it('refuses a sqlite default connection that is not in memory', function () {
    TestDatabaseGuard::assertSafe(false, 'sqlite', ['driver' => 'sqlite', 'database' => '/var/www/sesf-new/database/database.sqlite']);
})->throws(RuntimeException::class, 'is not allowed');

it('refuses a missing default connection config', function () {
    TestDatabaseGuard::assertSafe(false, 'nope', null);
})->throws(RuntimeException::class, 'default connection [nope]');

it('refuses cached configuration even when the connection is safe', function () use ($memorySqlite) {
    TestDatabaseGuard::assertSafe(true, 'sqlite', $memorySqlite);
})->throws(RuntimeException::class, 'configuration is cached');

it('applies the guard to the booted test application', function () {
    expect(fn () => TestDatabaseGuard::assertApplication(app()))->not->toThrow(RuntimeException::class);
});
