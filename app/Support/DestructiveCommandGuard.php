<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Refuses destructive artisan commands whose TARGET connection is not an ephemeral test database.
 *
 * DB::prohibitDestructiveCommands() is one global flag decided from the default connection, so a
 * command with --database=mysql can slip past it when the default is a test connection. This guard
 * runs on every CommandStarting and checks the connection the command really targets: the
 * --database option when it is given, otherwise the default connection.
 *
 * Destructive commands: db:wipe, migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback,
 * and schema:dump only with --prune (it deletes migration rows). db:seed is deliberately NOT
 * treated as destructive: it inserts rows and does not drop or truncate tables. Seeders that
 * truncate must be guarded in the seeder itself.
 */
final class DestructiveCommandGuard
{
    /** @var list<string> */
    private const ALWAYS_DESTRUCTIVE = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
    ];

    /**
     * Listener for Illuminate\Console\Events\CommandStarting, registered in AppServiceProvider.
     */
    public static function handle(CommandStarting $event): void
    {
        $default = (string) config('database.default');
        $connections = (array) config('database.connections', []);

        self::assertAllowed($event->command, $event->input, $default, $connections);
    }

    /**
     * @param  array<string, mixed>  $connections  config('database.connections')
     *
     * @throws RuntimeException when the command is destructive and its target is not allowed
     */
    public static function assertAllowed(string $command, InputInterface $input, string $default, array $connections): void
    {
        if (! self::isDestructive($command, $input)) {
            return;
        }

        $target = self::stringOption($input, 'database') ?? $default;
        $config = $connections[$target] ?? null;

        if (! is_array($config) || ! DestructiveCommandPolicy::allowsTarget($config, $target)) {
            throw new RuntimeException(sprintf(
                '"%s" is refused: its target connection "%s" is not an ephemeral test database (sqlite :memory: or testing_mariadb on sesf_test).',
                $command,
                $target,
            ));
        }
    }

    private static function isDestructive(string $command, InputInterface $input): bool
    {
        if (in_array($command, self::ALWAYS_DESTRUCTIVE, true)) {
            return true;
        }

        return $command === 'schema:dump' && $input->hasOption('prune') && (bool) $input->getOption('prune');
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        if (! $input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
