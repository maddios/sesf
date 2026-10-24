<?php

test('mysql and testing_mariadb use identical SQL modes', function () {
    $live = config('database.connections.mysql.modes');
    $test = config('database.connections.testing_mariadb.modes');

    expect($live)->toBeArray()
        ->and($live)->toBe($test);
});

test('both connections are non-strict and use the legacy zero-date-friendly modes', function (string $connection) {
    $modes = config("database.connections.{$connection}.modes");

    expect(config("database.connections.{$connection}.strict"))->toBeFalse()
        ->and($modes)->toBe([
            'STRICT_TRANS_TABLES',
            'ERROR_FOR_DIVISION_BY_ZERO',
            'NO_AUTO_CREATE_USER',
            'NO_ENGINE_SUBSTITUTION',
        ])
        ->and($modes)->not->toContain('NO_ZERO_DATE')
        ->and($modes)->not->toContain('NO_ZERO_IN_DATE')
        ->and($modes)->not->toContain('ONLY_FULL_GROUP_BY');
})->with(['mysql', 'testing_mariadb']);
