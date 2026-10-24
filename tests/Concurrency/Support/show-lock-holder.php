<?php

/*
 * Child process for tests/Concurrency/InvoiceAllocationRaceTest.php. Not a test file.
 *
 *   php show-lock-holder.php <show_id> <hold_ms>
 *
 * Locks the car_show row (SELECT ... FOR UPDATE) in a transaction on the testing_mariadb
 * connection, prints "LOCKED", holds the lock for <hold_ms> milliseconds, then commits.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

[, $showId, $holdMs] = (array) ($_SERVER['argv'] ?? []) + [null, null, null];

try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $config = config('database.connections.'.LegacyTestDatabase::CONNECTION);
    LegacyTestDatabase::assertSafe(is_array($config) ? $config : []);
    config(['database.default' => LegacyTestDatabase::CONNECTION]);

    DB::beginTransaction();
    DB::selectOne('select id from car_show where id = ? for update', [(int) $showId]);
    fwrite(STDOUT, 'LOCKED'.PHP_EOL);
    fflush(STDOUT);
    usleep(max(0, (int) $holdMs) * 1000);
    DB::commit();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR '.get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}
