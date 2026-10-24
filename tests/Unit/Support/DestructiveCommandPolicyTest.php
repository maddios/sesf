<?php

use App\Support\DestructiveCommandPolicy;

/*
 * Destructive commands (migrate:fresh, db:wipe, ...) are prohibited unless the default connection
 * is in-memory SQLite or testing_mariadb. The decision never looks at APP_ENV.
 */
dataset('destructive command cases', [
    'mysql sesf_dev is refused' => [
        ['driver' => 'mysql', 'database' => 'sesf_dev'], 'mysql', true,
    ],
    'mysql with APP_ENV=testing is still refused' => [
        ['driver' => 'mysql', 'database' => 'sesf_dev'], 'mysql', true,
    ],
    'testing_mariadb sesf_test is allowed' => [
        ['driver' => 'mysql', 'database' => 'sesf_test'], 'testing_mariadb', false,
    ],
    'sqlite in-memory is allowed' => [
        ['driver' => 'sqlite', 'database' => ':memory:'], 'sqlite', false,
    ],
    'sqlite file is refused' => [
        ['driver' => 'sqlite', 'database' => '/var/www/sesf-dev-store/db.sqlite'], 'sqlite', true,
    ],
]);

test('destructive commands are prohibited unless the default is a test database', function (array $config, string $name, bool $expected) {
    expect(DestructiveCommandPolicy::shouldProhibit($config, $name))->toBe($expected);
})->with('destructive command cases');
