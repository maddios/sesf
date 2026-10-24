<?php

use App\Support\DestructiveCommandGuard;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/*
 * Only the decision logic is tested. No destructive command is ever executed.
 */
function guardDefinition(): InputDefinition
{
    return new InputDefinition([
        new InputOption('database', null, InputOption::VALUE_REQUIRED),
        new InputOption('prune', null, InputOption::VALUE_NONE),
    ]);
}

/** @return array<string, array<string, mixed>> */
function guardConnections(string $testingDatabase = 'sesf_test'): array
{
    return [
        'mysql' => ['driver' => 'mysql', 'database' => 'sesf_dev'],
        'testing_mariadb' => ['driver' => 'mysql', 'database' => $testingDatabase],
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'sqlite_file' => ['driver' => 'sqlite', 'database' => '/var/www/sesf-dev-store/db.sqlite'],
    ];
}

/**
 * @param  array<string, mixed>  $params
 * @param  array<string, array<string, mixed>>  $connections
 */
function guardRefuses(string $command, array $params, string $default, array $connections): bool
{
    try {
        DestructiveCommandGuard::assertAllowed($command, new ArrayInput($params, guardDefinition()), $default, $connections);

        return false;
    } catch (RuntimeException) {
        return true;
    }
}

test('the --database target decides, not the default connection', function (array $params, string $default, bool $refused) {
    expect(guardRefuses('migrate:fresh', $params, $default, guardConnections()))->toBe($refused);
})->with([
    'database=mysql while default is testing_mariadb is refused' => [['--database' => 'mysql'], 'testing_mariadb', true],
    'database=testing_mariadb is allowed' => [['--database' => 'testing_mariadb'], 'testing_mariadb', false],
    'database=sqlite memory is allowed' => [['--database' => 'sqlite'], 'mysql', false],
    'database=sqlite file is refused' => [['--database' => 'sqlite_file'], 'sqlite', true],
    'unknown connection name is refused' => [['--database' => 'nope'], 'testing_mariadb', true],
]);

test('without --database the default connection is the target', function (string $default, bool $refused) {
    expect(guardRefuses('db:wipe', [], $default, guardConnections()))->toBe($refused);
})->with([
    'default mysql is refused' => ['mysql', true],
    'default testing_mariadb on sesf_test is allowed' => ['testing_mariadb', false],
    'default sqlite memory is allowed' => ['sqlite', false],
]);

test('testing_mariadb pointed at sesf_dev (env trick) is refused by the name check', function () {
    $connections = guardConnections(testingDatabase: 'sesf_dev');

    expect(guardRefuses('migrate:fresh', [], 'testing_mariadb', $connections))->toBeTrue()
        ->and(guardRefuses('migrate:fresh', ['--database' => 'testing_mariadb'], 'mysql', $connections))->toBeTrue();
});

test('every destructive command is guarded', function (string $command) {
    expect(guardRefuses($command, ['--database' => 'mysql'], 'testing_mariadb', guardConnections()))->toBeTrue();
})->with(['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback']);

test('schema:dump is guarded only with --prune', function () {
    $connections = guardConnections();

    expect(guardRefuses('schema:dump', ['--database' => 'mysql'], 'mysql', $connections))->toBeFalse()
        ->and(guardRefuses('schema:dump', ['--database' => 'mysql', '--prune' => true], 'mysql', $connections))->toBeTrue();
});

test('non-destructive commands are never refused by the guard', function (string $command) {
    expect(guardRefuses($command, ['--database' => 'mysql'], 'mysql', guardConnections()))->toBeFalse();
})->with(['migrate:status', 'db:seed', 'db:show', 'route:list']);
