<?php

/*
 * Child process for tests/Concurrency/InvoiceAllocationRaceTest.php. Not a test file.
 *
 *   php invoice-race-worker.php <plain|outer> <show_id> <user_id> <barrier_lock_name>
 *
 * 1. Boots the app on the testing_mariadb connection (same safety guard as the test suite).
 * 2. outer mode: opens a transaction and reads show_registration, so its snapshot is taken
 *    before any worker has registered. plain mode: no outer transaction.
 * 3. Prints "READY", then waits on GET_LOCK(<barrier>) held by the parent. All workers are
 *    released together when the parent releases it.
 * 4. Creates one ShowRegistration for (show, user), commits, and prints one JSON line.
 */

use App\Models\ShowRegistration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyTestDatabase;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

[, $mode, $showId, $userId, $barrier] = (array) ($_SERVER['argv'] ?? []) + [null, null, null, null, null];

$emit = static function (array $payload): void {
    fwrite(STDOUT, json_encode($payload).PHP_EOL);
    fflush(STDOUT);
};

try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $config = config('database.connections.'.LegacyTestDatabase::CONNECTION);
    LegacyTestDatabase::assertSafe(is_array($config) ? $config : []);
    config(['database.default' => LegacyTestDatabase::CONNECTION]);

    if ((string) DB::selectOne('select database() as db')->db !== LegacyTestDatabase::REQUIRED_DATABASE) {
        throw new RuntimeException('Worker is not connected to '.LegacyTestDatabase::REQUIRED_DATABASE);
    }

    if ($mode === 'outer') {
        DB::beginTransaction();
        // Consistent read: fixes this transaction's REPEATABLE READ snapshot now.
        DB::table('show_registration')->where('show_id', (int) $showId)->count();
    }

    $emit(['ready' => true]);

    $got = (int) DB::selectOne('select get_lock(?, 60) as got', [$barrier])->got;
    DB::selectOne('select release_lock(?) as released', [$barrier]);
    if ($got !== 1) {
        throw new RuntimeException('Barrier lock not acquired.');
    }

    $registration = ShowRegistration::query()->create([
        'show_id' => (int) $showId,
        'user_id' => (int) $userId,
    ]);

    if ($mode === 'outer') {
        DB::commit();
    }

    $emit(['ok' => true, 'id' => $registration->id, 'invoice_id' => $registration->invoice_id]);
    exit(0);
} catch (Throwable $e) {
    $emit(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
